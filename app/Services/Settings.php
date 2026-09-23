<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Réglages de l'entreprise : valeurs par défaut de config/entreprise.php,
 * surchargées par ce qui a été enregistré dans la table `settings`.
 */
class Settings
{
    private const CACHE_KEY = 'settings.all';

    private const GROUPS = ['company', 'bank', 'vat', 'branding', 'documents', 'insurance', 'pdf', 'mail', 'push'];

    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    /** @return array<string, mixed> */
    public function group(string $group): array
    {
        return $this->all()[$group] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());

        $values = Arr::only(config('entreprise'), self::GROUPS);
        foreach ($stored as $key => $value) {
            Arr::set($values, $key, $value);
        }

        return $this->values = $values;
    }

    /**
     * Enregistre des réglages ; les clés sont en notation pointée (« company.phone »).
     *
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
        $this->values = null;
    }
}
