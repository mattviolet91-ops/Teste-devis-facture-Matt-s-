<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_pages_are_in_french_and_hide_details(): void
    {
        $this->get('/page-qui-nexiste-pas')->assertNotFound()->assertSee('Page introuvable')->assertSee('Retour à l\'accueil', false);

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('invoices.index'))->assertForbidden()->assertSee('Accès refusé')->assertSee('Cette page est réservée au gérant.');
    }

    public function test_offline_list_never_offers_invoices_to_a_commercial(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => 'Travaux', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Faîtage', 'quantity' => '1', 'unit_price' => '100']]]);
        $quote = Quote::query()->firstOrFail();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard']);
        $invoice = Invoice::query()->firstOrFail();
        $this->getJson(route('offline.pages'))->assertOk()->assertJsonFragment([route('invoices.show', $invoice->id)]);

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $urls = $this->getJson(route('offline.pages'))->assertOk()->json('urls');
        $this->assertContains(route('clients.show', $client->id), $urls);
        $this->assertEmpty(array_filter($urls, fn ($url) => str_contains($url, '/factures') || str_contains($url, '/paiements') || str_contains($url, '/relances')));
    }

    public function test_security_check_command_reports_problems(): void
    {
        $this->admin();
        $this->artisan('app:security-check')->expectsOutputToContain('Mode debug désactivé')->assertFailed();
    }
}
