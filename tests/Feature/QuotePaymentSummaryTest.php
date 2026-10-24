<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Devis accepté : déjà réglé et reste à payer, pour le gérant et pour le client. */
class QuotePaymentSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-24 10:00');
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(Quote $quote, string $kind, ?string $percent = null): Invoice
    {
        $this->post(route('quotes.invoice', $quote), array_filter(['kind' => $kind, 'percent' => $percent]))->assertSessionHasNoErrors();
        $invoice = Invoice::query()->latest('id')->first();
        $this->post(route('invoices.send', $invoice))->assertSessionHasNoErrors();

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, string $amount): void
    {
        $this->post(route('payments.store', $invoice), ['amount' => $amount, 'paid_at' => '2026-10-24', 'method' => 'virement'])->assertSessionHasNoErrors();
    }

    public function test_remaining_amount_is_shown_on_the_accepted_quote(): void
    {
        $this->post(route('quotes.store'), ['client_id' => Client::factory()->create()->id, 'title' => 'Toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réfection', 'quantity' => '1', 'unit_price' => '2000']]])->assertSessionHasNoErrors();
        $quote = Quote::query()->sole();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));

        // Accepté, rien de facturé ni de réglé.
        $this->get(route('quotes.show', $quote))->assertSeeInOrder(['Déjà réglé par le client', Money::format(0), 'Reste à payer', Money::format(200000)], false);

        // Acompte de 30 % réglé.
        $deposit = $this->invoice($quote, 'deposit', '30');
        $this->pay($deposit, '600');
        $this->get(route('quotes.show', $quote))
            ->assertSeeInOrder(['Déjà réglé par le client', Money::format(60000), 'Reste à payer', Money::format(140000), 'dont pas encore facturé', Money::format(140000)], false);

        // Solde facturé, réglé en partie : le client voit ce qu'il reste et le lien de la facture.
        $final = $this->invoice($quote, 'final');
        $this->pay($final, '400');
        $this->get(route('quotes.show', $quote))->assertSeeInOrder(['Reste à payer', Money::format(100000)], false)->assertDontSee('dont pas encore facturé');
        $this->get(route('portal.quote', $quote->fresh()->public_token))->assertOk()
            ->assertSeeInOrder(['Règlement', 'Déjà réglé', Money::format(100000), 'Reste à payer', Money::format(100000)], false)
            ->assertSee(route('portal.invoice', $final->public_token));

        // Tout réglé.
        $this->pay($final, '1000');
        $this->get(route('quotes.show', $quote))->assertSee('Soldé');
        $this->get(route('portal.quote', $quote->fresh()->public_token))->assertSee('Tout est réglé, merci !');

        // Le commercial ne voit pas les règlements.
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('quotes.show', $quote))->assertOk()->assertDontSee('Reste à payer');
    }
}
