<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Intervention;
use App\Models\Quote;
use App\Models\User;
use App\Models\Worksite;
use App\Support\Maps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterfaceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->admin());
        $this->client = Client::factory()->create(['last_name' => 'Durand', 'phone' => '06 11 22 33 44']);
        Worksite::factory()->for($this->client)->create(['address' => '4 rue des Roses', 'postal_code' => '91300', 'city' => 'Massy']);
    }

    private function sentQuote(): Quote
    {
        $this->post(route('quotes.store'), ['client_id' => $this->client->id, 'title' => 'Faîtage', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Faîtage', 'quantity' => '1', 'unit_price' => '800']]]);
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->post(route('quotes.send', $quote));

        return $quote->fresh();
    }

    public function test_back_button_on_detail_pages_only(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertDontSee('data-back', false);
        $this->get(route('clients.index'))->assertDontSee('data-back', false);
        $this->get(route('clients.show', $this->client))->assertSee('data-back', false)->assertSee('href="'.route('clients.index').'" data-back', false);

        $quote = $this->sentQuote();
        $this->get(route('quotes.show', $quote))->assertSee('href="'.route('quotes.index').'" data-back', false);
        $this->get(route('clients.edit', $this->client))->assertSee('href="'.route('clients.show', $this->client).'" data-back', false);
    }

    public function test_quick_action_bar_follows_the_quote_status(): void
    {
        $quote = $this->sentQuote();
        $page = $this->get(route('quotes.show', $quote))->assertOk()->assertSee('class="quick-bar"', false);
        $page->assertSee('Relancer')->assertSee('data-quick-submit="accept-form"', false)->assertSee('id="accept-form"', false);

        $this->post(route('quotes.accept', $quote));
        $this->get(route('quotes.show', $quote))->assertSee('Planifier')->assertSee('data-open-sheet="invoice-dialog"', false);
    }

    public function test_swipe_marks_a_planning_item_done_and_lists_offer_actions(): void
    {
        $rdv = Intervention::query()->create(['kind' => 'rdv', 'client_id' => $this->client->id, 'title' => 'Visite',
            'starts_on' => today()->toDateString(), 'ends_on' => today()->toDateString(), 'start_time' => '10:00']);
        $this->get(route('planning.index'))->assertSee('data-swipe', false)->assertSee(route('planning.done', $rdv));

        $this->from(route('planning.index'))->post(route('planning.done', $rdv))->assertRedirect(route('planning.index'));
        $this->assertSame('done', $rdv->fresh()->status);

        $this->sentQuote();
        $this->get(route('quotes.index'))->assertSee('data-swipe', false)->assertSee('relance=1', false);
    }

    public function test_today_block_shows_appointments_route_and_calls(): void
    {
        Intervention::query()->create(['kind' => 'rdv', 'client_id' => $this->client->id, 'worksite_id' => $this->client->worksites()->value('id'),
            'title' => 'Visite toiture', 'starts_on' => today()->toDateString(), 'ends_on' => today()->toDateString(), 'start_time' => '14:00']);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Aujourd\'hui', false)->assertSee('14h00 · '.$this->client->displayName())
            ->assertSee('https://maps.apple.com/?daddr=', false)
            ->assertSee('href="tel:0611223344"', false);

        // Le commercial voit aussi son programme du jour.
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('dashboard'))->assertOk()->assertSee('Visite toiture');
    }

    public function test_empty_lists_offer_a_button_and_display_options_exist(): void
    {
        $this->get(route('catalog.index', ['q' => 'zzzz-introuvable']))->assertSee('Ajouter une prestation');
        $this->get(route('payments.index'))->assertSee('Factures à encaisser');
        $this->get(route('dashboard'))->assertSee('data-pref-toggle="big"', false)->assertDontSee('Plein soleil');
    }

    public function test_addresses_open_apple_plans_with_driving_directions(): void
    {
        $this->assertSame(
            'https://maps.apple.com/?daddr=12%20rue%20des%20Tilleuls%2C%2091300%20Massy&dirflg=d',
            Maps::directions('12 rue des Tilleuls, 91300 Massy'),
        );
    }

    public function test_directions_offer_apple_plans_waze_or_google_maps(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        Worksite::factory()->for($client)->create(['address' => '12 rue des Tilleuls', 'postal_code' => '91300', 'city' => 'Massy']);

        $this->get(route('clients.show', $client))->assertOk()
            ->assertSee('data-nav="12 rue des Tilleuls, 91300 Massy"', false)
            ->assertSee('data-nav-app="apple"', false)->assertSee('data-nav-app="waze"', false)->assertSee('data-nav-app="google"', false)
            ->assertSee('data-nav-pref', false);
    }
}
