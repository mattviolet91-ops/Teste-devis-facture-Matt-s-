<?php

namespace App\Services;

use App\Models\Intervention;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Météo des chantiers et rendez-vous des prochains jours.
 *
 * - Localisation : Géoplateforme de l'IGN (service public, gratuit) ;
 * - Prévisions : MET Norway (gratuites, usage professionnel autorisé, licence CC BY 4.0).
 *
 * Seuls le code postal et la ville (ou l'adresse du rendez-vous) sont envoyés.
 * Les pages lisent uniquement le cache : les prévisions sont téléchargées par la
 * tâche planifiée « app:weather » (toutes les heures), jamais pendant l'affichage.
 */
class WeatherService
{
    /** Nombre de jours couverts par les prévisions. */
    public const HORIZON_DAYS = 8;

    /** Seuils d'alerte pour un travail en toiture. */
    public const RAIN_MM = 2.0;

    public const WIND_KMH = 40;

    public const GUST_KMH = 60;

    public const FROST_C = 0.0;

    private const GEOCODER = 'https://data.geopf.fr/geocodage/search';

    private const FORECAST = 'https://api.met.no/weatherapi/locationforecast/2.0/complete';

    /**
     * Météo d'une intervention, jour par jour (seulement les jours à venir couverts par les prévisions).
     *
     * @return array<string, array{rain: float, wind: int, gust: ?int, tmin: ?float, tmax: ?float, alerts: list<string>}>
     */
    public function forIntervention(Intervention $intervention, bool $fetch = false): array
    {
        $first = $intervention->starts_on->copy()->max(today());
        $last = $intervention->ends_on->copy()->min(today()->addDays(self::HORIZON_DAYS - 1));
        if ($first->gt($last) || $intervention->status === 'cancelled') {
            return [];
        }

        $place = $this->place($intervention);
        $coordinates = $place ? $this->coordinates($place, $fetch) : null;
        $forecast = $coordinates ? $this->forecast($coordinates[0], $coordinates[1], $fetch) : null;
        if (! $forecast) {
            return [];
        }

        $days = [];
        for ($day = $first->copy(); $day->lte($last); $day->addDay()) {
            if (isset($forecast[$day->toDateString()])) {
                $info = $forecast[$day->toDateString()];
                $days[$day->toDateString()] = $info + ['alerts' => self::alerts($info)];
            }
        }

        return $days;
    }

    /** Météo d'un jour précis de l'intervention (planning). */
    public function forDay(Intervention $intervention, Carbon $day): ?array
    {
        return $this->forIntervention($intervention)[$day->toDateString()] ?? null;
    }

    /**
     * Alertes pour un jour : pluie, vent fort, gel.
     *
     * @param  array{rain: float, wind: int, gust: ?int, tmin: ?float}  $day
     * @return list<string>
     */
    public static function alerts(array $day): array
    {
        $alerts = [];
        if ($day['rain'] >= self::RAIN_MM) {
            $alerts[] = 'Pluie '.self::number($day['rain']).' mm';
        }
        if ($day['wind'] >= self::WIND_KMH || ($day['gust'] ?? 0) >= self::GUST_KMH) {
            $alerts[] = 'Vent fort '.max($day['wind'], $day['gust'] ?? 0).' km/h';
        }
        if ($day['tmin'] !== null && $day['tmin'] <= self::FROST_C) {
            $alerts[] = 'Gel '.self::number($day['tmin']).' °C';
        }

        return $alerts;
    }

    /** Résumé court : « 18° · sec · vent 20 km/h ». */
    public static function summary(array $day): string
    {
        return collect([
            $day['tmax'] !== null ? round($day['tmax']).'°' : null,
            $day['rain'] >= 0.5 ? self::number($day['rain']).' mm' : 'sec',
            'vent '.$day['wind'].' km/h',
        ])->filter()->implode(' · ');
    }

    /** Télécharge les prévisions des chantiers et rendez-vous à venir (tâche planifiée). */
    public function warm(): int
    {
        $count = 0;
        Intervention::query()->where('status', 'planned')->visible()
            ->between(today(), today()->addDays(self::HORIZON_DAYS - 1))
            ->with(['client', 'worksite'])->get()
            ->each(function (Intervention $intervention) use (&$count) {
                if ($this->forIntervention($intervention, fetch: true) !== []) {
                    $count++;
                }
            });

        return $count;
    }

    /** Lieu à localiser : adresse du rendez-vous, sinon code postal et ville du chantier ou du client. */
    public function place(Intervention $intervention): ?string
    {
        foreach ([$intervention->worksite, $intervention->client] as $owner) {
            if ($owner && $owner->postal_code && $owner->city) {
                $fromOwner = trim($owner->postal_code.' '.$owner->city);
                break;
            }
        }

        $place = trim((string) ($intervention->location ?: ($fromOwner ?? '')));

        return mb_strlen($place) >= 3 ? $place : null;
    }

    /** @return array{0: float, 1: float}|null */
    public function coordinates(string $place, bool $fetch = false): ?array
    {
        $key = 'weather.geo.'.md5(mb_strtolower($place));
        $cached = Cache::get($key);
        if ($cached !== null || ! $fetch) {
            return $cached ?: null;
        }

        try {
            $feature = Http::timeout(10)->connectTimeout(5)->retry(2, 500, throw: false)->acceptJson()
                ->get(self::GEOCODER, ['q' => $place, 'limit' => 1, 'index' => 'address'])
                ->throw()->json('features.0');
            $point = $feature['geometry']['coordinates'] ?? null;
            $coordinates = $point ? [round((float) $point[1], 2), round((float) $point[0], 2)] : false;
        } catch (Throwable $e) {
            Log::info('Météo : localisation impossible', ['error' => $e->getMessage()]);

            return null;
        }

        // Lieu introuvable : on ne réessaie pas avant une semaine.
        $coordinates ? Cache::forever($key, $coordinates) : Cache::put($key, false, now()->addWeek());

        return $coordinates ?: null;
    }

    /** @return array<string, array{rain: float, wind: int, gust: ?int, tmin: ?float, tmax: ?float}>|null */
    public function forecast(float $lat, float $lon, bool $fetch = false): ?array
    {
        $key = 'weather.forecast.'.$lat.','.$lon;
        $cached = Cache::get($key);
        if ($cached !== null || ! $fetch) {
            return $cached ?: null;
        }

        try {
            $series = Http::timeout(15)->connectTimeout(5)->retry(2, 500, throw: false)->acceptJson()
                ->withUserAgent('MattsCouverture-Gestion/1.0 '.config('app.url'))
                ->get(self::FORECAST, ['lat' => $lat, 'lon' => $lon])
                ->throw()->json('properties.timeseries', []);
        } catch (Throwable $e) {
            Log::info('Météo : prévisions indisponibles', ['error' => $e->getMessage()]);
            Cache::put($key, false, now()->addMinutes(20));

            return null;
        }

        $days = self::aggregate($series);
        Cache::put($key, $days ?: false, now()->addHours(3));

        return $days ?: null;
    }

    /**
     * Regroupe les prévisions heure par heure en journées de travail (7 h – 19 h, heure de Paris).
     *
     * @param  list<array<string, mixed>>  $series
     * @return array<string, array{rain: float, wind: int, gust: ?int, tmin: ?float, tmax: ?float}>
     */
    public static function aggregate(array $series): array
    {
        $days = [];
        $hourlyDays = [];
        $timezone = config('app.timezone', 'Europe/Paris');

        foreach ($series as $entry) {
            $time = Carbon::parse($entry['time'])->setTimezone($timezone);
            $date = $time->toDateString();
            $details = $entry['data']['instant']['details'] ?? [];
            $day = $days[$date] ?? ['rain' => 0.0, 'wind' => 0, 'gust' => null, 'tmin' => null, 'tmax' => null];
            $workHours = $time->hour >= 7 && $time->hour < 19;

            if (isset($details['air_temperature'])) {
                $t = (float) $details['air_temperature'];
                $day['tmin'] = $day['tmin'] === null ? $t : min($day['tmin'], $t);
                if ($workHours) {
                    $day['tmax'] = $day['tmax'] === null ? $t : max($day['tmax'], $t);
                }
            }
            if ($workHours && isset($details['wind_speed'])) {
                $day['wind'] = max($day['wind'], (int) round($details['wind_speed'] * 3.6));
            }
            if ($workHours && isset($details['wind_speed_of_gust'])) {
                $day['gust'] = max($day['gust'] ?? 0, (int) round($details['wind_speed_of_gust'] * 3.6));
            }

            // Pluie : au pas horaire quand il existe, sinon par tranches de 6 heures.
            if (isset($entry['data']['next_1_hours']['details']['precipitation_amount'])) {
                $hourlyDays[$date] = true;
                if ($workHours) {
                    $day['rain'] += (float) $entry['data']['next_1_hours']['details']['precipitation_amount'];
                }
            } elseif (empty($hourlyDays[$date]) && isset($entry['data']['next_6_hours']['details']['precipitation_amount'])
                && $time->hour >= 5 && $time->hour < 17) {
                $day['rain'] += (float) $entry['data']['next_6_hours']['details']['precipitation_amount'];
            }

            $days[$date] = $day;
        }

        return array_map(fn ($day) => ['rain' => round($day['rain'], 1)] + $day, array_filter($days, fn ($day) => $day['tmax'] !== null));
    }

    private static function number(float $value): string
    {
        return str_replace('.', ',', (string) round($value, abs($value) < 10 ? 1 : 0));
    }
}
