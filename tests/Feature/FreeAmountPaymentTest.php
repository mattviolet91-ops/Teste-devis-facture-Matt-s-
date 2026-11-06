<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\PaymentRequest;
use App\Models\Quote;
use App\Models\User;
use App\Services\DocumentCalculator;
use App\Services\InvoiceService;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/** Encaisser un montant libre (par carte ou déjà reçu) et payer une partie d'une facture. */
class FreeAmountPaymentTest extends TestCase
{
    use RefreshDatabase;

    private string $myposKey = '';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 10:00');
        Mail::fake();
        $this->app->instance(PushService::class, Mockery::mock(PushService::class)->shouldIgnoreMissing());

        $merchant = openssl_pkey_new(['private_key_bits' => 1024]);
        openssl_pkey_export($merchant, $merchantPem);
        $mypos = openssl_pkey_new(['private_key_bits' => 1024]);
        openssl_pkey_export($mypos, $this->myposKey);
        $package = base64_encode(json_encode([
            'sid' => '000000000000123', 'cn' => '61938166610', 'pk' => $merchantPem,
            'pc' => openssl_pkey_get_details($mypos)['key'], 'idx' => 1,
        ]));

        $this->actingAs($this->admin());
        $this->put(route('settings.payments'), ['enabled' => '1', 'package' => $package])->assertSessionHasNoErrors();
        $this->client = Client::factory()->create(['last_name' => 'Petit', 'email' => 'petit@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function notify(OnlinePayment $attempt, string $amount, string $ref = '777'): TestResponse
    {
        $fields = ['IPCmethod' => 'IPCPurchaseNotify', 'SID' => '000000000000123', 'Amount' => $amount, 'Currency' => 'EUR', 'OrderID' => $attempt->order_id, 'IPC_Trnref' => $ref];
        openssl_sign(base64_encode(implode('-', $fields)), $signature, $this->myposKey, OPENSSL_ALGO_SHA256);

        return $this->post(route('portal.mypos.notify'), $fields + ['Signature' => base64_encode($signature)]);
    }

    private function acceptedQuote(): Quote
    {
        $this->post(route('quotes.store'), ['client_id' => $this->client->id, 'title' => 'Toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réfection', 'quantity' => '1', 'unit_price' => '3000']]])->assertSessionHasNoErrors();
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));

        return $quote->fresh();
    }

    public function test_free_amount_paid_by_card_creates_a_paid_invoice(): void
    {
        $this->get(route('payments.index'))->assertSee(route('payments.requests.create'));
        $this->get(route('payments.requests.create', ['client' => $this->client->id]))->assertOk()->assertSee('Encaisser un montant');

        $this->post(route('payments.requests.store'), [
            'client_id' => $this->client->id, 'kind' => 'standard', 'label' => 'Dépannage fuite', 'amount' => '180,50', 'mode' => 'card',
        ])->assertSessionHasNoErrors();
        $request = PaymentRequest::query()->sole();
        $this->assertSame([18050, 'pending', null], [$request->amount, $request->status, $request->invoice_id]);
        $this->assertSame(0, Invoice::query()->count(), 'Aucune facture tant que le client n\'a pas payé.');
        $this->get(route('payments.requests.show', $request))->assertSee($request->publicUrl())->assertSee('Payer sur ce téléphone');
        $this->get(route('payments.index'))->assertSee('Liens de paiement en attente')->assertSee('Dépannage fuite');
        auth()->logout();

        $this->get(route('portal.payment-request', $request->token))->assertOk()->assertSee('Dépannage fuite')->assertSee('Payer 180,50');
        $this->assertNotNull($request->fresh()->viewed_at);
        $form = $this->get(route('portal.payment-request.pay', $request->token))->assertOk()->viewData('form');
        $this->assertSame(['18050' => '180.50'], [(string) $request->amount => $form['fields']['Amount']]);
        $this->assertSame('Dépannage fuite', $form['fields']['Note']);

        $attempt = OnlinePayment::query()->sole();
        $this->notify($attempt, '180.50')->assertOk();
        $this->notify($attempt, '180.50')->assertOk();

        $invoice = Invoice::query()->sole();
        $this->assertSame(['standard', 'paid', 18050, $this->client->id], [$invoice->kind, $invoice->status, $invoice->total_ttc, $invoice->client_id]);
        $this->assertNotNull($invoice->number);
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame(['paid', $invoice->id], [$request->fresh()->status, $request->fresh()->invoice_id]);
        $this->get(route('portal.payment-request', $request->token))->assertSee('Paiement reçu')->assertSee(route('portal.invoice', $invoice->public_token));
        $this->get(route('portal.payment-request.pay', $request->token))->assertRedirect(route('portal.payment-request', $request->token));
    }

    public function test_wrong_amount_or_cancelled_request_creates_no_invoice(): void
    {
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'kind' => 'standard', 'label' => 'Petite réparation', 'amount' => '90', 'mode' => 'card']);
        $request = PaymentRequest::query()->sole();
        $this->get(route('portal.payment-request.pay', $request->token));
        $this->notify(OnlinePayment::query()->sole(), '1.00')->assertOk();
        $this->assertSame([0, 'pending'], [Invoice::query()->count(), $request->fresh()->status]);

        $this->delete(route('payments.requests.cancel', $request));
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->get(route('portal.payment-request', $request->token))->assertSee('n\'est plus valable', false);
        $this->get(route('portal.payment-request.pay', $request->token))->assertRedirect();
        $this->assertSame(1, OnlinePayment::query()->count());
    }

    public function test_amount_already_received_becomes_a_paid_deposit_on_the_quote(): void
    {
        $quote = $this->acceptedQuote();
        $this->get(route('quotes.show', $quote))->assertSee(route('payments.requests.create', ['client' => $this->client->id, 'devis' => $quote->id]));

        $this->post(route('payments.requests.store'), [
            'client_id' => $this->client->id, 'quote_id' => $quote->id, 'kind' => 'deposit', 'label' => 'Acompte toiture', 'amount' => '1 000',
            'mode' => 'received', 'paid_at' => '2026-11-06', 'method' => 'cheque', 'reference' => 'Chèque 1234',
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::query()->sole();
        $this->assertSame(['deposit', 'paid', 100000, $quote->id], [$invoice->kind, $invoice->status, $invoice->total_ttc, $invoice->quote_id]);
        $this->assertSame(['cheque', 'Chèque 1234'], [$invoice->payments()->first()->method, $invoice->payments()->first()->reference]);
        $this->assertSame(0, PaymentRequest::query()->count());

        $summary = $quote->fresh()->paymentSummary();
        $this->assertSame([100000, 200000], [$summary['deposits_paid'], $summary['remaining']]);
    }

    public function test_seven_day_legal_delay_is_respected(): void
    {
        $quote = $this->acceptedQuote();
        $quote->forceFill(['signed_at' => now()->subDays(2), 'signed_on_site' => true])->save();

        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'quote_id' => $quote->id, 'kind' => 'deposit', 'label' => 'Acompte', 'amount' => '500', 'mode' => 'card'])
            ->assertSessionHasErrors(['quote_id' => "Le devis {$quote->number} a été signé à domicile il y a moins de 7 jours : aucun paiement ne peut être reçu avant le 12/11/2026 (délai légal), sauf intervention urgente confirmée par le client."]);
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'kind' => 'standard', 'label' => 'Autre', 'amount' => '500', 'mode' => 'received', 'paid_at' => '2026-11-06', 'method' => 'especes'])
            ->assertSessionHasErrors('quote_id');
        $this->assertSame([0, 0], [Invoice::query()->count(), PaymentRequest::query()->count()]);

        // Lien créé avant la signature : le client ne peut pas payer pendant le délai.
        $quote->forceFill(['signed_at' => null])->save();
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'quote_id' => $quote->id, 'kind' => 'deposit', 'label' => 'Acompte', 'amount' => '500', 'mode' => 'card'])->assertSessionHasNoErrors();
        $quote->forceFill(['signed_at' => now()->subDays(2)])->save();
        $token = PaymentRequest::query()->sole()->token;
        $this->get(route('portal.payment-request', $token))->assertSee('délai légal de 7 jours')->assertDontSee('par carte</a>', false);
        $this->get(route('portal.payment-request.pay', $token))->assertRedirect();
        $this->assertSame(0, OnlinePayment::query()->count());
    }

    public function test_client_pays_part_of_an_invoice(): void
    {
        $this->post(route('invoices.store'), ['client_id' => $this->client->id, 'due_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réparation', 'quantity' => '1', 'unit_price' => '350']]]);
        $invoice = Invoice::query()->sole();
        $this->post(route('invoices.send', $invoice));
        $token = $invoice->fresh()->public_token;
        auth()->logout();

        $this->get(route('portal.invoice', $token))->assertSee('Payer une partie seulement');
        $this->get(route('portal.invoice.pay', ['token' => $token, 'montant' => '400']))->assertRedirect(route('portal.invoice', $token))->assertSessionHasErrors('montant');
        $this->get(route('portal.invoice.pay', ['token' => $token, 'montant' => '0,50']))->assertSessionHasErrors('montant');
        $this->assertSame(0, OnlinePayment::query()->count());

        $form = $this->get(route('portal.invoice.pay', ['token' => $token, 'montant' => '100']))->assertOk()->assertSee('100,00')->viewData('form');
        $this->assertSame('100.00', $form['fields']['Amount']);
        $this->notify(OnlinePayment::query()->sole(), '100.00')->assertOk();

        $invoice->refresh();
        $this->assertSame(['partial', 25000], [$invoice->status, $invoice->balance()]);
        $this->get(route('portal.invoice', $token))->assertSee('250,00');
    }

    public function test_invoice_total_is_exactly_the_amount_whatever_the_vat(): void
    {
        $service = app(InvoiceService::class);
        $calculator = app(DocumentCalculator::class);
        $line = ['type' => 'item', 'title' => 'Acompte', 'quantity' => 1000, 'unit' => 'forfait'];
        foreach ([1000, 2000, 550] as $rate) {
            foreach ([50000, 100, 1, 99999, 123456, 18050, 3] as $amount) {
                $lines = $service->amountLines($line + ['vat_rate' => $rate], $amount, $rate, false);
                $this->assertSame($amount, $calculator->calculate($lines)['total_ttc'], "$amount à $rate");
                $this->assertLessThanOrEqual(2, count($lines));
                if (count($lines) === 2) {
                    $this->assertSame(['Arrondi', 1, 0], [$lines[1]['title'], $lines[1]['unit_price'], $lines[1]['vat_rate']]);
                }
            }
        }
    }

    public function test_vat_registered_company_gets_an_invoice_of_exactly_the_amount(): void
    {
        app(Settings::class)->set(['vat.regime' => 'assujetti']);
        $this->get(route('payments.requests.create'))->assertSee('Taux de TVA');

        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'kind' => 'standard', 'label' => 'Dépannage', 'amount' => '500', 'vat_rate' => '1000',
            'mode' => 'received', 'paid_at' => '2026-11-06', 'method' => 'cb'])->assertSessionHasNoErrors();

        $invoice = Invoice::query()->with('lines')->sole();
        $this->assertSame([50000, 'paid', 'assujetti'], [$invoice->total_ttc, $invoice->status, $invoice->vat_regime]);
        $this->assertSame(['Dépannage', 'Arrondi'], $invoice->lines->pluck('title')->all());
        $this->assertSame([45454, 1], $invoice->lines->pluck('unit_price')->all());
    }

    public function test_commercial_cannot_collect_money(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('payments.requests.create'))->assertForbidden();
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id])->assertForbidden();
        $this->get(route('clients.show', $this->client))->assertOk()->assertDontSee(route('payments.requests.create', ['client' => $this->client->id]), false);
    }
}
