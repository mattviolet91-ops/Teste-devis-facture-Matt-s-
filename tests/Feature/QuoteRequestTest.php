<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Photo;
use App\Models\QuoteRequest;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

class QuoteRequestTest extends TestCase
{
    use RefreshDatabase;

    private $push;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00');
        config(['entreprise.client_url' => 'https://devis.matts-couverture.fr', 'app.url' => 'https://test.matts-couverture.fr']);
        $this->push = Mockery::mock(PushService::class);
        $this->app->instance(PushService::class, $this->push);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'started' => Crypt::encryptString((string) now()->subMinute()->timestamp),
            'civility' => 'Mme', 'last_name' => 'Garnier', 'first_name' => 'Julie', 'phone' => '06 45 67 89 01', 'email' => 'julie@example.com',
            'address' => '12 rue des Tilleuls', 'postal_code' => '91140', 'city' => 'Villebon-sur-Yvette',
            'works' => ['demoussage', 'zinguerie'], 'message' => 'Mousse sur le pan nord, gouttière qui déborde.',
            'availability' => 'Samedi matin', 'consent' => '1',
        ], $overrides);
    }

    public function test_request_from_the_client_address_creates_a_prospect_and_notifies_with_an_app_link(): void
    {
        $this->push->shouldReceive('send')->once()->withArgs(fn ($title, $body, $url) => $title === 'Nouvelle demande de devis : Mme Julie Garnier'
            && str_contains($body, 'Démoussage') && str_contains($body, 'Villebon') && str_starts_with($url, 'https://test.matts-couverture.fr/demandes/'));

        $this->get('https://devis.matts-couverture.fr/demande-de-devis')->assertOk()->assertSee('Demande de devis gratuit')->assertDontSee('Se déconnecter');
        $this->post('https://devis.matts-couverture.fr/demande-de-devis', $this->payload([
            'photos' => [UploadedFile::fake()->image('toit.jpg', 1600, 1200)],
        ]))->assertRedirect(route('portal.request.thanks'));

        $client = Client::query()->sole();
        $this->assertSame(['prospect', 'site', '91140'], [$client->status, $client->source, $client->postal_code]);
        $request = QuoteRequest::query()->sole();
        $this->assertSame('Villebon-sur-Yvette', $request->worksite->city);
        $this->assertSame(1, Photo::query()->where('client_id', $client->id)->count());

        $this->actingAs($this->admin());
        $this->get('https://test.matts-couverture.fr/')->assertSee('Demandes de devis (1)');
        $this->get(route('requests.index'))->assertOk()->assertSee('Garnier')->assertSee('https://devis.matts-couverture.fr/demande-de-devis');
        $this->get(route('requests.show', $request))->assertOk()->assertSee('Mousse sur le pan nord')->assertSee('Samedi matin');
        $this->post(route('requests.toggle', $request));
        $this->assertSame('handled', $request->fresh()->status);
    }

    public function test_existing_client_is_reused_and_robots_are_ignored(): void
    {
        $this->push->shouldReceive('send')->once();
        $known = Client::factory()->create(['phone' => '06 45 67 89 01', 'last_name' => 'Garnier']);

        $this->post(route('portal.request.store'), $this->payload(['address' => '', 'postal_code' => '', 'city' => '']))->assertRedirect(route('portal.request.thanks'));
        $this->assertSame(1, Client::query()->count());
        $this->assertSame($known->id, QuoteRequest::query()->sole()->client_id);

        // Robots : champ piège rempli, ou envoi instantané. Aucune demande créée, même page de remerciement.
        $this->post(route('portal.request.store'), $this->payload(['website' => 'http://spam.example']))->assertRedirect(route('portal.request.thanks'));
        $this->post(route('portal.request.store'), $this->payload(['started' => Crypt::encryptString((string) now()->timestamp)]))->assertRedirect(route('portal.request.thanks'));
        $this->assertSame(1, QuoteRequest::query()->count());
    }

    public function test_validation_asks_for_name_phone_works_and_consent(): void
    {
        $this->push->shouldReceive('send')->never();
        $this->post(route('portal.request.store'), $this->payload(['last_name' => '', 'phone' => '12', 'works' => [], 'message' => '', 'consent' => '']))
            ->assertSessionHasErrors(['last_name', 'phone', 'consent']);
        $this->post(route('portal.request.store'), $this->payload(['works' => [], 'message' => '']))->assertSessionHasErrors('works');
        $this->assertSame(0, Client::query()->count());
    }
}
