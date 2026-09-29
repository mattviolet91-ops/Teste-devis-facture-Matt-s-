<?php

namespace App\Services;

use App\Http\Controllers\SiteStatsCollectController;
use App\Models\SiteDailyStat;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Statistiques déjà comptées par WordPress.com (Jetpack Stats) : visites et pages vues
 * par jour, pages les plus vues, provenance et liens cliqués. Lecture seule : le site
 * n'est jamais modifié. Connexion par le compte WordPress.com (OAuth), jeton chiffré.
 */
class WordpressStatsService
{
    public const AUTHORIZE_URL = 'https://public-api.wordpress.com/oauth2/authorize';

    public const TOKEN_URL = 'https://public-api.wordpress.com/oauth2/token';

    public const API = 'https://public-api.wordpress.com/rest/v1.1/sites/';

    public function __construct(private readonly Settings $settings) {}

    public function hasApp(): bool
    {
        return filled($this->settings->get('site_stats.wpcom_client_id')) && filled($this->settings->get('site_stats.wpcom_client_secret'));
    }

    public function isConnected(): bool
    {
        return filled($this->settings->get('site_stats.wpcom_token')) && filled($this->settings->get('site_stats.wpcom_blog_id'));
    }

    /** Lien vers la page d'autorisation de WordPress.com. */
    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->settings->get('site_stats.wpcom_client_id'),
            'redirect_uri' => route('site-stats.wpcom.callback'),
            'response_type' => 'code',
            'state' => $state,
            'blog' => SiteStatsCollectController::host((string) $this->settings->get('company.website')),
        ]);
    }

    /** Échange le code reçu contre un jeton d'accès (limité au site choisi). */
    public function connect(string $code): void
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => $this->settings->get('site_stats.wpcom_client_id'),
            'client_secret' => $this->secret(),
            'redirect_uri' => route('site-stats.wpcom.callback'),
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);
        $token = $response->json('access_token');
        $blog = $response->json('blog_id');
        if (! $response->successful() || ! $token || ! $blog) {
            throw new RuntimeException('WordPress.com a refusé la connexion'.($response->json('error_description') ? ' : '.$response->json('error_description') : '.'));
        }

        $this->settings->set([
            'site_stats.wpcom_token' => Crypt::encryptString((string) $token),
            'site_stats.wpcom_blog_id' => (string) $blog,
            'site_stats.wpcom_connected_at' => now()->toIso8601String(),
            'site_stats.wpcom_last_error' => '',
        ]);
    }

    public function disconnect(): void
    {
        $this->settings->set([
            'site_stats.wpcom_token' => '',
            'site_stats.wpcom_blog_id' => '',
            'site_stats.wpcom_connected_at' => null,
            'site_stats.wpcom_top' => [],
        ]);
    }

    /** Importe les 30 derniers jours (tâche quotidienne). Retourne le nombre de jours enregistrés. */
    public function sync(int $days = 30): int
    {
        if (! $this->isConnected()) {
            return 0;
        }

        try {
            $visits = $this->get('stats/visits', ['unit' => 'day', 'quantity' => $days]);
            $fields = $visits['fields'] ?? ['period', 'views', 'visitors'];
            $iPeriod = array_search('period', $fields, true);
            $iViews = array_search('views', $fields, true);
            $iVisitors = array_search('visitors', $fields, true);
            $count = 0;
            foreach ($visits['data'] ?? [] as $row) {
                if ($iPeriod === false || ! isset($row[$iPeriod])) {
                    continue;
                }
                SiteDailyStat::query()->updateOrCreate(['day' => substr((string) $row[$iPeriod], 0, 10)], [
                    'views' => (int) ($iViews !== false ? ($row[$iViews] ?? 0) : 0),
                    'visitors' => (int) ($iVisitors !== false ? ($row[$iVisitors] ?? 0) : 0),
                ]);
                $count++;
            }

            $summary = ['period' => 'day', 'num' => $days, 'summarize' => 1];
            $this->settings->set([
                'site_stats.wpcom_top' => [
                    'pages' => $this->topPages($this->get('stats/top-posts', $summary + ['max' => 10])),
                    'referrers' => $this->referrers($this->get('stats/referrers', $summary + ['max' => 10])),
                    'clicks' => $this->clicks($this->get('stats/clicks', $summary + ['max' => 10])),
                    'days' => $days,
                ],
                'site_stats.wpcom_last_sync_at' => now()->toIso8601String(),
                'site_stats.wpcom_last_error' => '',
            ]);

            return $count;
        } catch (Throwable $e) {
            Log::info('Statistiques WordPress.com indisponibles', ['error' => $e->getMessage()]);
            $this->settings->set(['site_stats.wpcom_last_error' => mb_substr($e->getMessage(), 0, 250)]);

            return 0;
        }
    }

    /** @return array<string, mixed> */
    private function get(string $path, array $query): array
    {
        $token = Crypt::decryptString((string) $this->settings->get('site_stats.wpcom_token'));
        $response = Http::withToken($token)->acceptJson()->timeout(20)->connectTimeout(8)
            ->get(self::API.rawurlencode((string) $this->settings->get('site_stats.wpcom_blog_id')).'/'.$path, $query);
        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Accès refusé par WordPress.com : reconnectez le compte.');
        }

        return (array) $response->throw()->json();
    }

    /** Résumé de la période, ou jours additionnés si WordPress.com renvoie le détail par jour. */
    private function summary(array $data): array
    {
        if (isset($data['summary']) && is_array($data['summary'])) {
            return [$data['summary']];
        }

        return array_values(array_filter((array) ($data['days'] ?? []), 'is_array'));
    }

    /** @return list<array{title: string, url: ?string, views: int}> */
    private function topPages(array $data): array
    {
        $pages = [];
        foreach ($this->summary($data) as $day) {
            foreach ((array) ($day['postviews'] ?? []) as $post) {
                $key = (string) ($post['href'] ?? $post['title'] ?? '');
                $pages[$key] ??= ['title' => html_entity_decode((string) ($post['title'] ?? $key)), 'url' => $post['href'] ?? null, 'views' => 0];
                $pages[$key]['views'] += (int) ($post['views'] ?? 0);
            }
        }

        return $this->top($pages);
    }

    /** @return list<array{title: string, url: ?string, views: int}> */
    private function referrers(array $data): array
    {
        $groups = [];
        foreach ($this->summary($data) as $day) {
            foreach ((array) ($day['groups'] ?? []) as $group) {
                $name = (string) ($group['name'] ?? $group['group'] ?? 'Autre');
                $groups[$name] ??= ['title' => $name, 'url' => $group['url'] ?? null, 'views' => 0];
                $groups[$name]['views'] += (int) ($group['total'] ?? $group['views'] ?? 0);
            }
        }

        return $this->top($groups);
    }

    /** @return list<array{title: string, url: ?string, views: int}> */
    private function clicks(array $data): array
    {
        $clicks = [];
        foreach ($this->summary($data) as $day) {
            foreach ((array) ($day['clicks'] ?? []) as $click) {
                $name = (string) ($click['name'] ?? $click['url'] ?? '');
                $clicks[$name] ??= ['title' => $name, 'url' => $click['url'] ?? null, 'views' => 0];
                $clicks[$name]['views'] += (int) ($click['views'] ?? 0);
            }
        }

        return $this->top($clicks);
    }

    private function top(array $rows): array
    {
        usort($rows, fn ($a, $b) => $b['views'] <=> $a['views']);

        return array_slice(array_values(array_filter($rows, fn ($r) => $r['views'] > 0 && $r['title'] !== '')), 0, 10);
    }

    private function secret(): string
    {
        $stored = (string) $this->settings->get('site_stats.wpcom_client_secret');
        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return $stored;
        }
    }
}
