<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Intervention;
use App\Models\Quote;
use App\Models\Worksite;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class PlanningTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Worksite $worksite;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00'); // mercredi
        $this->client = Client::factory()->create(['civility' => 'Mme', 'last_name' => 'Leroy', 'phone' => '06 99 88 77 66']);
        $this->worksite = Worksite::factory()->for($this->client)->create(['address' => '3 rue des Lilas', 'postal_code' => '91140', 'city' => 'Villebon-sur-Yvette']);
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function acceptedQuote(): Quote
    {
        $this->post(route('quotes.store'), [
            'client_id' => $this->client->id, 'worksite_id' => $this->worksite->id, 'title' => 'Démoussage de la toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '900']],
        ]);
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));

        return $quote->fresh();
    }

    public function test_accepted_quote_is_planned_and_client_can_be_notified(): void
    {
        $quote = $this->acceptedQuote();
        $this->get(route('planning.index'))->assertOk()->assertSee('Devis acceptés à planifier')->assertSee($quote->number);
        $this->get(route('quotes.show', $quote))->assertSee(route('planning.create', ['devis' => $quote->id]), false);
        $this->get(route('planning.create', ['devis' => $quote->id]))->assertOk()->assertSee('Démoussage de la toiture');

        $this->post(route('planning.store'), [
            'client_id' => $this->client->id, 'worksite_id' => $this->worksite->id, 'quote_id' => $quote->id,
            'title' => 'Démoussage de la toiture', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-13', 'start_time' => '08:00',
        ])->assertSessionHasNoErrors();
        $intervention = Intervention::query()->sole();

        $this->get(route('planning.show', $intervention))->assertOk()
            ->assertSee('Bonjour Madame Leroy,')
            ->assertSee('du lundi 12 octobre à 8h00 au mardi 13 octobre au 3 rue des Lilas, 91140 Villebon-sur-Yvette');

        $this->get(route('planning.index', ['date' => '2026-10-13']))->assertSee('Leroy')->assertSee('jour 2/2')->assertDontSee('Devis acceptés à planifier');
        $this->get(route('planning.index', ['vue' => 'mois']))->assertOk()->assertSee('Leroy');
        $this->get(route('dashboard'))->assertSee('Prochains rendez-vous et chantiers');

        $ics = $this->get(route('planning.ics', $intervention))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringContainsString('DTSTART;TZID=Europe/Paris:20261012T080000', $ics);
        $this->assertStringContainsString('LOCATION:3 rue des Lilas\, 91140 Villebon-sur-Yvette', $ics);
    }

    public function test_quote_of_another_client_is_refused_and_end_before_start(): void
    {
        $other = Client::factory()->create();
        $quote = $this->acceptedQuote();

        $this->post(route('planning.store'), [
            'client_id' => $other->id, 'quote_id' => $quote->id, 'title' => 'X', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-10',
        ])->assertSessionHasErrors(['quote_id', 'ends_on']);
        $this->assertSame(0, Intervention::query()->count());
    }

    public function test_evening_notification_lists_tomorrow(): void
    {
        Intervention::query()->create(['client_id' => $this->client->id, 'worksite_id' => $this->worksite->id, 'title' => 'Faîtage', 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-08', 'start_time' => '09:30']);

        $push = Mockery::mock(PushService::class);
        $this->app->instance(PushService::class, $push);
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body) => $title === 'Demain : 1 chantier' && str_contains($body, '9h30') && str_contains($body, 'Villebon'));
        $this->artisan('app:planning-notifications')->assertSuccessful();
    }

    public function test_appointments_with_or_without_client_and_reminder_one_hour_before(): void
    {
        $this->get(route('planning.create', ['type' => 'rdv', 'client' => $this->client->id]))->assertOk()
            ->assertSee('Visite pour devis (métré)')->assertSee('js/planning.js', false);

        // Visite chez un prospect : heure de fin par défaut 1 heure après.
        $this->post(route('planning.store'), [
            'kind' => 'rdv', 'client_id' => $this->client->id, 'worksite_id' => $this->worksite->id,
            'title' => 'Visite pour devis (métré)', 'starts_on' => '2026-10-08', 'start_time' => '14:00',
        ])->assertSessionHasNoErrors();
        $rdv = Intervention::query()->latest('id')->firstOrFail();
        $this->assertSame(['rdv', '15:00', '2026-10-08'], [$rdv->kind, $rdv->end_time, $rdv->ends_on->toDateString()]);

        $this->get(route('planning.show', $rdv))->assertOk()
            ->assertSee('Nous vous confirmons notre rendez-vous le jeudi 8 octobre de 14h00 à 15h00 au 3 rue des Lilas', false)
            ->assertSee('Faire le devis');

        // Rendez-vous fournisseur, sans client : lieu libre, heure obligatoire.
        $this->post(route('planning.store'), ['kind' => 'rdv', 'title' => 'Rendez-vous fournisseur', 'starts_on' => '2026-10-08'])
            ->assertSessionHasErrors('start_time');
        $this->post(route('planning.store'), [
            'kind' => 'rdv', 'title' => 'Rendez-vous fournisseur', 'location' => 'Point P Massy', 'starts_on' => '2026-10-08', 'start_time' => '08:00', 'end_time' => '08:30',
        ])->assertSessionHasNoErrors();
        $supplier = Intervention::query()->latest('id')->firstOrFail();
        $this->get(route('planning.show', $supplier))->assertOk()->assertSee('Point P Massy')->assertDontSee('Prévenir le client');
        $this->get(route('planning.ics', $supplier))->assertSee('DTEND;TZID=Europe/Paris:20261008T083000', false);

        // Un chantier sans client reste refusé.
        $this->post(route('planning.store'), ['kind' => 'chantier', 'title' => 'X', 'starts_on' => '2026-10-08'])->assertSessionHasErrors('client_id');

        $this->get(route('planning.index', ['date' => '2026-10-08']))->assertOk()
            ->assertSee('14h00 – 15h00 · M')->assertSee('8h00 – 8h30 · Rendez-vous fournisseur')->assertSee('Point P Massy');

        $push = Mockery::mock(PushService::class);
        $this->app->instance(PushService::class, $push);
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body) => $title === 'Demain : 2 rendez-vous' && str_contains($body, '8h00 RDV Rendez-vous fournisseur (Point P Massy)'));
        $this->artisan('app:planning-notifications')->assertSuccessful();

        // Le jour même, 1 heure avant : un seul rappel.
        Carbon::setTestNow('2026-10-08 13:05');
        $push->shouldReceive('send')->once()->withArgs(fn ($title) => str_starts_with($title, 'Rendez-vous à 14h00'));
        $this->artisan('app:planning-notifications --bientot')->assertSuccessful();
        $this->artisan('app:planning-notifications --bientot')->assertSuccessful();
    }
}
