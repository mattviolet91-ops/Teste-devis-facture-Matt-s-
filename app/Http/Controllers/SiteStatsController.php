<?php

namespace App\Http\Controllers;

use App\Models\QuoteRequest;
use App\Models\SiteDailyStat;
use App\Models\SiteEvent;
use App\Services\Settings;
use App\Services\WordpressStatsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/** Statistiques du site internet : visites, provenance et clics de contact (réservé au gérant). */
class SiteStatsController extends Controller
{
    public const PERIODS = ['7' => '7 jours', '30' => '30 jours', '90' => '3 mois', '365' => '1 an'];

    public function index(Request $request, Settings $settings, WordpressStatsService $wpcom): View
    {
        $days = array_key_exists((string) $request->query('jours'), self::PERIODS) ? (int) $request->query('jours') : 30;
        $from = today()->subDays($days - 1);
        $events = SiteEvent::query()->where('created_at', '>=', $from);

        // Visites = visiteurs différents chaque jour (l'empreinte anonyme change chaque jour).
        $perDay = (clone $events)->where('type', 'pv')
            ->selectRaw('DATE(created_at) as day, COUNT(*) as views, COUNT(DISTINCT visitor) as visits')
            ->groupBy(DB::raw('DATE(created_at)'))->get()->keyBy('day');
        $series = collect(range(0, $days - 1))->map(function ($i) use ($from, $perDay) {
            $day = $from->copy()->addDays($i)->toDateString();

            return ['day' => $day, 'visits' => (int) ($perDay[$day]->visits ?? 0), 'views' => (int) ($perDay[$day]->views ?? 0)];
        });

        $byType = (clone $events)->selectRaw('type, COUNT(*) as n')->groupBy('type')->pluck('n', 'type');
        $contacts = collect(SiteEvent::CONTACT_TYPES)->sum(fn ($t) => (int) ($byType[$t] ?? 0));
        $visits = $series->sum('visits');
        $contactVisits = (clone $events)->whereIn('type', SiteEvent::CONTACT_TYPES)
            ->selectRaw('COUNT(DISTINCT '.$this->visitorDayExpression().') as n')->value('n');

        $wpDays = SiteDailyStat::query()->where('day', '>=', $from->toDateString())->orderBy('day')->get()->keyBy(fn ($d) => $d->day->toDateString());

        return view('statistics.site', [
            'days' => $days,
            'from' => $from,
            'series' => $series,
            'totals' => [
                'visits' => $visits,
                'views' => $series->sum('views'),
                'contacts' => $contacts,
                'rate' => $visits > 0 ? round((int) $contactVisits * 100 / $visits, 1) : null,
                'requests' => QuoteRequest::query()->where('created_at', '>=', $from)->count(),
            ],
            'byType' => $byType,
            'pages' => (clone $events)->where('type', 'pv')->selectRaw('path, COUNT(*) as n')->groupBy('path')->orderByDesc('n')->limit(10)->pluck('n', 'path'),
            'sources' => $this->sources(clone $events),
            'buttons' => (clone $events)->whereIn('type', ['cta', 'tel', 'whatsapp', 'form'])->whereNotNull('label')
                ->selectRaw('type, label, COUNT(*) as n')->groupBy('type', 'label')->orderByDesc('n')->limit(10)->get(),
            'devices' => (clone $events)->where('type', 'pv')->selectRaw('device, COUNT(DISTINCT '.$this->visitorDayExpression().') as n')->groupBy('device')->pluck('n', 'device'),
            'lastEvent' => SiteEvent::query()->latest('id')->value('created_at'),
            'snippet' => '<script defer src="'.rtrim((string) config('entreprise.client_url'), '/').'/s.js"></script>',
            'siteHost' => SiteStatsCollectController::host((string) $settings->get('company.website')),
            'wp' => [
                'hasApp' => $wpcom->hasApp(),
                'connected' => $wpcom->isConnected(),
                'clientId' => $settings->get('site_stats.wpcom_client_id'),
                'lastSync' => $settings->get('site_stats.wpcom_last_sync_at'),
                'error' => $settings->get('site_stats.wpcom_last_error'),
                'top' => (array) $settings->get('site_stats.wpcom_top', []),
                'series' => $series->map(fn ($d) => ['day' => $d['day'], 'views' => (int) ($wpDays[$d['day']]->views ?? 0), 'visitors' => (int) ($wpDays[$d['day']]->visitors ?? 0)]),
                'totalViews' => (int) $wpDays->sum('views'),
                'totalVisitors' => (int) $wpDays->sum('visitors'),
                'callback' => route('site-stats.wpcom.callback'),
            ],
        ]);
    }

    /** Identifiants de l'application WordPress.com (créée une fois sur developer.wordpress.com). */
    public function saveApp(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:40', 'regex:/^[0-9]+$/'],
            'client_secret' => ['required', 'string', 'min:20', 'max:200'],
        ], ['client_id.regex' => 'Le « Client ID » est un nombre.'], ['client_id' => 'Client ID', 'client_secret' => 'Client Secret']);

        $settings->set([
            'site_stats.wpcom_client_id' => $data['client_id'],
            'site_stats.wpcom_client_secret' => Crypt::encryptString(trim($data['client_secret'])),
        ]);

        return redirect()->to(route('site-stats.index').'#wordpress')->with('status', 'Application WordPress.com enregistrée : appuyez sur « Connecter mon compte WordPress.com ».');
    }

    public function connect(Request $request, WordpressStatsService $wpcom): RedirectResponse
    {
        abort_unless($wpcom->hasApp(), 404);
        $state = Str::random(40);
        $request->session()->put('wpcom_state', $state);

        return redirect()->away($wpcom->authorizeUrl($state));
    }

    public function callback(Request $request, WordpressStatsService $wpcom): RedirectResponse
    {
        $expected = $request->session()->pull('wpcom_state');
        $back = redirect()->to(route('site-stats.index').'#wordpress');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state')) || ! $request->filled('code')) {
            return $back->withErrors(['wpcom' => 'Connexion à WordPress.com annulée ou expirée. Recommencez.']);
        }

        try {
            $wpcom->connect((string) $request->query('code'));
        } catch (Throwable $e) {
            return $back->withErrors(['wpcom' => $e->getMessage()]);
        }
        $days = $wpcom->sync();

        return $back->with('status', 'Compte WordPress.com connecté : '.$days.' jour(s) de statistiques importé(s).');
    }

    public function sync(WordpressStatsService $wpcom): RedirectResponse
    {
        $days = $wpcom->sync();

        return redirect()->to(route('site-stats.index').'#wordpress')->with('status', $days ? 'Statistiques WordPress.com mises à jour.' : 'Aucune donnée reçue de WordPress.com.');
    }

    public function disconnect(WordpressStatsService $wpcom): RedirectResponse
    {
        $wpcom->disconnect();

        return redirect()->to(route('site-stats.index').'#wordpress')->with('status', 'Compte WordPress.com déconnecté.');
    }

    /** Provenance des visites : moteurs de recherche, réseaux sociaux, accès direct, autres sites. */
    private function sources($events): Collection
    {
        $rows = $events->where('type', 'pv')->whereNotNull('referrer_host')
            ->selectRaw('referrer_host, COUNT(*) as n')->groupBy('referrer_host')->pluck('n', 'referrer_host');

        return $rows->reduce(function (Collection $carry, $n, $host) {
            $label = match (true) {
                $host === '(direct)' => 'Accès direct (adresse tapée, favori, QR code)',
                (bool) preg_match('/(^|\.)google\./', $host) => 'Google',
                (bool) preg_match('/(^|\.)(bing|qwant|duckduckgo|ecosia|yahoo|yandex)\./', $host) => 'Autres moteurs de recherche',
                (bool) preg_match('/(facebook|fb\.|instagram|messenger)/', $host) => 'Facebook / Instagram',
                (bool) preg_match('/pagesjaunes/', $host) => 'PagesJaunes',
                (bool) preg_match('/(travaux|habitatpresto|starofservice|houzz|leboncoin)/', $host) => $host,
                default => $host,
            };

            return $carry->put($label, (int) $carry->get($label, 0) + (int) $n);
        }, collect())->sortDesc()->take(10);
    }

    /** « visiteur + jour » : une visite par personne et par jour. */
    private function visitorDayExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "visitor || '|' || DATE(created_at)"
            : "CONCAT(visitor, '|', DATE(created_at))";
    }
}
