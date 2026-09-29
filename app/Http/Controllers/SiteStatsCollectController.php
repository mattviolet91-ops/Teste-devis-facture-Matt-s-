<?php

namespace App\Http\Controllers;

use App\Models\SiteEvent;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Reçoit les visites et clics envoyés par le script « s.js » du site internet.
 * Aucune adresse IP n'est enregistrée : seule une empreinte anonyme, qui change
 * chaque jour, permet de compter les visiteurs différents.
 */
class SiteStatsCollectController extends Controller
{
    private const BOTS = '/(bot|crawl|spider|slurp|facebookexternalhit|preview|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|scrapy)/i';

    public function __invoke(Request $request, Settings $settings): Response
    {
        $done = response()->noContent();
        $agent = (string) $request->userAgent();
        if ($agent === '' || preg_match(self::BOTS, $agent)) {
            return $done;
        }

        $data = json_decode((string) $request->getContent(), true);
        if (! is_array($data) || ! array_key_exists((string) ($data['t'] ?? ''), SiteEvent::TYPES)) {
            return $done;
        }

        // Seulement les pages du site de l'entreprise (Réglages → Entreprise → Site internet).
        $siteHost = self::host((string) $settings->get('company.website'));
        $page = parse_url((string) ($data['u'] ?? ''));
        $pageHost = self::host((string) ($data['u'] ?? ''));
        if (! $siteHost || $pageHost !== $siteHost) {
            return $done;
        }

        $referrer = self::host((string) ($data['r'] ?? ''));
        $day = now()->toDateString();

        SiteEvent::query()->create([
            'type' => $data['t'],
            'path' => Str::limit(($page['path'] ?? '/') ?: '/', 190, ''),
            'label' => ($label = trim((string) ($data['l'] ?? ''))) !== '' ? Str::limit(strip_tags($label), 80, '') : null,
            // « (direct) » : adresse tapée ou favori ; vide : navigation à l'intérieur du site.
            'referrer_host' => $referrer === null ? '(direct)' : ($referrer !== $siteHost ? Str::limit($referrer, 120, '') : null),
            'device' => preg_match('/Mobi|Android|iPhone/i', $agent) ? 'mobile' : 'ordinateur',
            'visitor' => substr(hash_hmac('sha256', $request->ip().'|'.$agent.'|'.$day, (string) config('app.key')), 0, 16),
        ]);

        return $done;
    }

    /** « https://www.exemple.fr/page » → « exemple.fr » */
    public static function host(string $url): ?string
    {
        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }
}
