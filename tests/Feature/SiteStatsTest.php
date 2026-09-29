<?php

namespace Tests\Feature;

use App\Models\SiteDailyStat;
use App\Models\SiteEvent;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteStatsTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        app(Settings::class)->set(['company.website' => 'https://www.exemple-couvreur.fr']);
    }

    private function collect(array $data, string $agent = self::MOBILE, string $ip = '203.0.113.7')
    {
        return $this->call('POST', route('portal.site.collect'), [], [], [], ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'text/plain'], json_encode($data));
    }

    public function test_script_counts_visits_and_contact_clicks_without_personal_data(): void
    {
        $this->collect(['t' => 'pv', 'u' => 'https://exemple-couvreur.fr/renovation-toiture/', 'r' => 'https://www.google.com/'])->assertNoContent();
        $this->collect(['t' => 'pv', 'u' => 'https://exemple-couvreur.fr/contact/', 'r' => 'https://exemple-couvreur.fr/renovation-toiture/'])->assertNoContent();
        $this->collect(['t' => 'tel', 'u' => 'https://exemple-couvreur.fr/contact/', 'l' => '0600000000'])->assertNoContent();
        $this->collect(['t' => 'pv', 'u' => 'https://exemple-couvreur.fr/', 'r' => ''], ip: '198.51.100.9');

        // Ignorés : un autre site, un robot, un type inconnu.
        $this->collect(['t' => 'pv', 'u' => 'https://autre-site.fr/']);
        $this->collect(['t' => 'pv', 'u' => 'https://exemple-couvreur.fr/'], 'Googlebot/2.1 (+http://www.google.com/bot.html)');
        $this->collect(['t' => 'hack', 'u' => 'https://exemple-couvreur.fr/']);

        $this->assertSame(4, SiteEvent::query()->count());
        $first = SiteEvent::query()->first();
        $this->assertSame(['pv', '/renovation-toiture/', 'google.com', 'mobile'], [$first->type, $first->path, $first->referrer_host, $first->device]);
        $this->assertSame(16, strlen($first->visitor));
        $this->assertNull(SiteEvent::query()->where('path', '/contact/')->where('type', 'pv')->value('referrer_host'));
        $this->assertSame('(direct)', SiteEvent::query()->where('path', '/')->value('referrer_host'));
        $this->assertStringNotContainsString('203.0.113.7', json_encode(SiteEvent::query()->get()->toArray()));

        $this->actingAs($this->admin());
        $page = $this->get(route('site-stats.index'))->assertOk();
        $this->assertSame(['visits' => 2, 'views' => 3, 'contacts' => 1, 'rate' => 50.0], array_intersect_key($page->viewData('totals'), array_flip(['visits', 'views', 'contacts', 'rate'])));
        $page->assertSee('Google')->assertSee('Accès direct')->assertSee('/renovation-toiture/')->assertSee('Actif');
    }

    public function test_page_explains_installation_and_is_reserved_to_the_admin(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('site-stats.index'))->assertOk()->assertSee('Pas encore installé')->assertSee('/s.js&quot;&gt;&lt;/script&gt;', false)
            ->assertSee('developer.wordpress.com/apps');
        $this->get(route('statistics'))->assertSee(route('site-stats.index'));

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('site-stats.index'))->assertForbidden();
    }

    public function test_wordpress_com_account_is_connected_and_stats_are_imported(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('site-stats.wpcom.app'), ['client_id' => '123456', 'client_secret' => str_repeat('s', 64)])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString(str_repeat('s', 64), (string) app(Settings::class)->get('site_stats.wpcom_client_secret'));

        $redirect = $this->get(route('site-stats.wpcom.connect'))->assertRedirect();
        $this->assertStringStartsWith('https://public-api.wordpress.com/oauth2/authorize?', $redirect->headers->get('Location'));
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['123456', 'exemple-couvreur.fr'], [$query['client_id'], $query['blog']]);

        // Mauvais « state » : refusé.
        $this->get(route('site-stats.wpcom.callback', ['code' => 'x', 'state' => 'faux']))->assertSessionHasErrors('wpcom');

        Http::fake([
            'public-api.wordpress.com/oauth2/token' => Http::response(['access_token' => 'jeton-secret', 'blog_id' => 987, 'token_type' => 'bearer']),
            'public-api.wordpress.com/rest/v1.1/sites/987/stats/visits*' => Http::response(['fields' => ['period', 'views', 'visitors'], 'data' => [
                [today()->subDay()->toDateString(), 40, 25], [today()->toDateString(), 12, 9],
            ]]),
            'public-api.wordpress.com/rest/v1.1/sites/987/stats/top-posts*' => Http::response(['summary' => ['postviews' => [
                ['title' => 'Rénovation de toiture', 'href' => 'https://exemple-couvreur.fr/renovation/', 'views' => 30],
                ['title' => 'Accueil', 'href' => 'https://exemple-couvreur.fr/', 'views' => 20],
            ]]]),
            'public-api.wordpress.com/rest/v1.1/sites/987/stats/referrers*' => Http::response(['summary' => ['groups' => [['name' => 'Search Engines', 'total' => 18]]]]),
            'public-api.wordpress.com/rest/v1.1/sites/987/stats/clicks*' => Http::response(['summary' => ['clicks' => [['name' => 'wa.me', 'views' => 4]]]]),
        ]);

        $this->get(route('site-stats.wpcom.connect'));
        $state = session('wpcom_state');
        $this->get(route('site-stats.wpcom.callback', ['code' => 'code-ok', 'state' => $state]))->assertSessionHasNoErrors();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/stats/visits') && $r->hasHeader('Authorization', 'Bearer jeton-secret'));
        $this->assertSame(52, (int) SiteDailyStat::query()->sum('views'));
        $this->assertStringNotContainsString('jeton-secret', (string) app(Settings::class)->get('site_stats.wpcom_token'));

        $this->get(route('site-stats.index'))->assertOk()->assertSee('Connecté')->assertSee('Rénovation de toiture')->assertSee('Search Engines')->assertSee('wa.me');
        $this->artisan('app:site-stats')->assertSuccessful();

        $this->delete(route('site-stats.wpcom.disconnect'));
        $this->assertSame('', app(Settings::class)->get('site_stats.wpcom_token'));
    }
}
