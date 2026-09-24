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
        $this->get(route('dashboard'))->assertSee('Prochaines interventions');

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
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body) => $title === 'Demain : 1 intervention' && str_contains($body, '9h30') && str_contains($body, 'Villebon'));
        $this->artisan('app:planning-notifications')->assertSuccessful();
    }
}
