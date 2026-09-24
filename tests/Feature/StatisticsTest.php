<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function quoteFor(Client $client, string $price): Quote
    {
        $this->post(route('quotes.store'), [
            'client_id' => $client->id, 'title' => 'Démoussage', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => $price]],
        ])->assertSessionHasNoErrors();

        return Quote::query()->latest('id')->firstOrFail();
    }

    public function test_statistics_group_quotes_and_revenue_by_source(): void
    {
        Carbon::setTestNow('2026-09-25 10:00');
        $this->actingAs($this->admin());
        $stand = Client::factory()->create(['source' => 'stand', 'last_name' => 'Stand']);
        $pub = Client::factory()->create(['source' => 'publicite', 'last_name' => 'Pub']);

        $quote = $this->quoteFor($stand, '1000');
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard']);
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->post(route('invoices.send', $invoice));

        $other = $this->quoteFor($pub, '500');
        $this->post(route('quotes.send', $other));

        $response = $this->get(route('statistics'))->assertOk()
            ->assertSee('Stand en magasin')
            ->assertSee('Publicité')
            ->assertSee('100 % acceptés')
            ->assertSee('0 % acceptés');

        $rows = $response->viewData('rows');
        $this->assertSame(1, $rows['stand']['accepted']);
        $this->assertSame($invoice->fresh()->total_ht, $rows['stand']['revenue']);
        $this->assertSame(1, $rows['publicite']['sent']);
        $this->assertSame(0, $rows['publicite']['revenue']);
        $this->assertArrayNotHasKey('google', $rows->all());
    }

    public function test_clients_can_be_filtered_by_source(): void
    {
        $this->actingAs($this->admin());
        Client::factory()->create(['source' => 'stand', 'last_name' => 'Magasin']);
        Client::factory()->create(['source' => 'site', 'last_name' => 'Internet']);

        $this->get(route('clients.index', ['source' => 'stand']))->assertOk()
            ->assertSee('Magasin')->assertDontSee('Internet');
        $this->get(route('statistics', ['periode' => 'tout']))->assertOk();
    }
}
