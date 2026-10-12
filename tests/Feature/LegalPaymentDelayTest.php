<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Particulier : facturer est toujours possible ; seul un devis signé chez le client
 * impose d'attendre 7 jours avant d'être payé (sauf réparation urgente).
 */
class LegalPaymentDelayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00');
        $this->actingAs($this->admin());
        app(Settings::class)->set(['bank.card_link' => 'https://pay.example.com/matts']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(300, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 10, 60, 290, 70, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function quote(string $type = 'particulier', bool $urgent = false): Quote
    {
        $client = Client::factory()->create(['type' => $type, 'last_name' => 'Martin', 'company_name' => $type === 'professionnel' ? 'SCI Martin' : null]);
        $this->post(route('quotes.store'), array_filter([
            'client_id' => $client->id, 'title' => 'Fuite toiture', 'validity_days' => 30,
            'urgent_repair' => $urgent ? '1' : null,
            'lines' => [['type' => 'item', 'title' => 'Recherche de fuite', 'quantity' => '1', 'unit_price' => '300']],
        ]))->assertSessionHasNoErrors();

        return Quote::query()->latest('id')->firstOrFail();
    }

    private function signOnSite(Quote $quote): void
    {
        $this->post(route('quotes.on-site.sign', $quote), ['name' => 'M. Martin', 'signature' => $this->signature(), 'agree' => '1'])->assertSessionHasNoErrors();
    }

    private function invoice(Quote $quote): Invoice
    {
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard'])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->post(route('invoices.send', $invoice))->assertSessionHasNoErrors();

        return $invoice->fresh();
    }

    public function test_quote_signed_at_home_can_be_invoiced_now_and_paid_from_the_8th_day(): void
    {
        $quote = $this->quote();
        $this->signOnSite($quote);

        $this->get(route('quotes.show', $quote))->assertSee('vous pouvez facturer dès maintenant')->assertSee('20/10/2026');
        $invoice = $this->invoice($quote);

        // Facture envoyée tout de suite, payable à partir du 20/10 (signé le 12/10, jour non compté).
        $this->assertSame(['sent', '2026-10-12', '2026-10-20'], [$invoice->status, $invoice->issue_date->toDateString(), $invoice->due_date->toDateString()]);
        $this->assertSame('2026-10-20', $invoice->paymentWaitUntil()?->toDateString());
        $this->assertNull($invoice->cardPaymentUrl(), 'Pas de paiement par carte proposé avant le 20/10.');
        $this->assertFalse($invoice->isOverdue());

        $this->get(route('invoices.show', $invoice))->assertSee('la facture peut être envoyée dès maintenant')->assertSee('20/10/2026');
        $this->get(route('portal.invoice', $invoice->public_token))->assertOk()
            ->assertSee('Paiement à partir du 20/10/2026')->assertSee('payable à partir du 20/10/2026')->assertDontSee('Payer par carte bancaire');

        // À partir du 20/10 : paiement par carte proposé.
        Carbon::setTestNow('2026-10-20 08:00');
        $this->assertNull($invoice->fresh()->paymentWaitUntil());
        $this->assertNotNull($invoice->fresh()->cardPaymentUrl());
    }

    public function test_no_waiting_after_an_online_signature_an_urgent_repair_or_for_a_company(): void
    {
        // Signature en ligne (contrat à distance).
        $online = $this->quote();
        $this->post(route('quotes.send', $online));
        $this->post(route('portal.quote.sign', $online->fresh()->public_token), ['name' => 'M. Martin', 'signature' => $this->signature(), 'agree' => '1'])->assertSessionHasNoErrors();
        $invoice = $this->invoice($online->fresh());
        $this->assertSame([null, '2026-10-12'], [$invoice->paymentWaitUntil(), $invoice->due_date->toDateString()]);
        $this->assertNotNull($invoice->cardPaymentUrl());

        // Réparation urgente demandée par le client, signée chez lui.
        $urgent = $this->quote(urgent: true);
        $this->assertTrue($urgent->urgent_repair);
        $this->get(route('quotes.edit', $urgent))->assertSee('name="urgent_repair" value="1" checked', false);
        $this->signOnSite($urgent);
        $this->get(route('quotes.show', $urgent))->assertSee('Réparation urgente demandée par le client');
        $invoice = $this->invoice($urgent->fresh());
        $this->assertSame([null, '2026-10-12'], [$invoice->paymentWaitUntil(), $invoice->due_date->toDateString()]);

        // Client professionnel.
        $pro = $this->quote('professionnel');
        $this->signOnSite($pro);
        $this->assertNull($this->invoice($pro->fresh())->paymentWaitUntil());
    }

    public function test_after_seven_days_the_invoice_is_due_normally(): void
    {
        $quote = $this->quote();
        $this->signOnSite($quote);
        Carbon::setTestNow('2026-10-21 09:00');

        $invoice = $this->invoice($quote->fresh());

        $this->assertSame([null, '2026-10-21'], [$invoice->paymentWaitUntil(), $invoice->due_date->toDateString()]);
        $this->assertNull($invoice->legalPaymentFrom());
    }
}
