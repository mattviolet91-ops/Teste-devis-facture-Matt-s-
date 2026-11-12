<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Quote;
use App\Models\User;
use App\Services\PushService;
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

    private function sentInvoice(string $price = '350'): Invoice
    {
        $this->post(route('invoices.store'), ['client_id' => $this->client->id, 'due_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réparation', 'quantity' => '1', 'unit_price' => $price]]])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->post(route('invoices.send', $invoice))->assertSessionHasNoErrors();

        return $invoice->fresh();
    }

    public function test_free_amount_paid_by_card_creates_no_invoice_and_is_attached_later(): void
    {
        $this->get(route('payments.index'))->assertSee(route('payments.requests.create'));
        $this->get(route('payments.requests.create', ['client' => $this->client->id]))->assertOk()->assertSee('Facture concernée')->assertDontSee('Facture créée');

        // Sans facture, le libellé est obligatoire.
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'amount' => '180,50', 'mode' => 'card'])->assertSessionHasErrors('label');
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'label' => 'Dépannage fuite', 'amount' => '180,50', 'mode' => 'card'])->assertSessionHasNoErrors();
        $request = PaymentRequest::query()->sole();
        $this->assertSame([18050, 'pending', null], [$request->amount, $request->status, $request->invoice_id]);
        $this->get(route('payments.requests.show', $request))->assertSee($request->publicUrl())->assertSee('à rattacher');
        auth()->logout();

        $this->get(route('portal.payment-request', $request->token))->assertOk()->assertSee('Dépannage fuite')->assertSee('Payer 180,50');
        $form = $this->get(route('portal.payment-request.pay', $request->token))->assertOk()->viewData('form');
        $this->assertSame(['180.50', 'Depannage fuite'], [$form['fields']['Amount'], $form['fields']['Note']]);

        $attempt = OnlinePayment::query()->sole();
        $this->notify($attempt, '180.50')->assertOk();
        $this->notify($attempt, '180.50')->assertOk();

        // Aucune facture : un encaissement « à rattacher », compté dans l'encaissé.
        $this->assertSame(0, Invoice::query()->count());
        $payment = Payment::query()->sole();
        $this->assertSame([null, $this->client->id, 18050, 'mypos', 'Dépannage fuite'], [$payment->invoice_id, $payment->client_id, $payment->amount, $payment->method, $payment->label]);
        $this->assertSame('paid', $request->fresh()->status);
        $this->assertSame(18050, (int) Payment::query()->counted()->sum('amount'));
        $this->get(route('portal.payment-request', $request->token))->assertSee('Paiement reçu')->assertSee('Votre facture vous sera adressée');

        $this->actingAs($this->admin());
        $this->get(route('payments.index'))->assertSee('À rattacher à une facture')->assertSee('Dépannage fuite')->assertSee('Aucune facture envoyée à régler');
        $this->get(route('dashboard'))->assertSee('Encaissements à rattacher à une facture');

        // La facture est faite ensuite : l'encaissement s'y rattache.
        $invoice = $this->sentInvoice('180,50');
        $this->get(route('invoices.show', $invoice))->assertSee('déjà encaissé')->assertSee('Rattacher à cette facture');
        $this->post(route('payments.attach', $payment), ['invoice_id' => $invoice->id])->assertRedirect(route('invoices.show', $invoice).'#paiements');
        $this->assertSame(['paid', 18050, $invoice->id], [$invoice->fresh()->status, $invoice->fresh()->amount_paid, $payment->fresh()->invoice_id]);
        $this->get(route('payments.index'))->assertDontSee('À rattacher à une facture');
        // Déjà rattaché : pas une seconde fois.
        $this->post(route('payments.attach', $payment), ['invoice_id' => $invoice->id])->assertSessionHasErrors('attach_'.$payment->id);
    }

    public function test_wrong_amount_or_cancelled_request_records_nothing(): void
    {
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'label' => 'Petite réparation', 'amount' => '90', 'mode' => 'card']);
        $request = PaymentRequest::query()->sole();
        $this->get(route('portal.payment-request.pay', $request->token));
        $this->notify(OnlinePayment::query()->sole(), '1.00')->assertOk();
        $this->assertSame([0, 0, 'pending'], [Invoice::query()->count(), Payment::query()->count(), $request->fresh()->status]);

        $this->delete(route('payments.requests.cancel', $request));
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->get(route('portal.payment-request', $request->token))->assertSee('n\'est plus valable', false);
        $this->get(route('portal.payment-request.pay', $request->token))->assertRedirect();
        $this->assertSame(1, OnlinePayment::query()->count());
    }

    public function test_amount_already_received_goes_on_the_chosen_invoice_or_waits(): void
    {
        $invoice = $this->sentInvoice('350');
        $this->get(route('payments.requests.create', ['client' => $this->client->id]))->assertSee($invoice->number.' — reste 350,00');

        // Sur une facture existante : paiement partiel, aucune nouvelle facture.
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => '100',
            'mode' => 'received', 'paid_at' => '2026-11-06', 'method' => 'cheque', 'reference' => 'Chèque 1234'])
            ->assertRedirect(route('invoices.show', $invoice).'#paiements');
        $this->assertSame([1, 'partial', 25000], [Invoice::query()->count(), $invoice->fresh()->status, $invoice->fresh()->balance()]);
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => '300',
            'mode' => 'received', 'paid_at' => '2026-11-06', 'method' => 'especes'])->assertSessionHasErrors('invoice_id');

        // Sans facture : l'encaissement attend.
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'label' => 'Acompte toiture', 'amount' => '1 000',
            'mode' => 'received', 'paid_at' => '2026-11-06', 'method' => 'especes'])->assertRedirect(route('payments.index').'#a-rattacher');
        $waiting = Payment::query()->unallocated()->sole();
        $this->assertSame([100000, 'especes', 'Acompte toiture'], [$waiting->amount, $waiting->method, $waiting->label]);
        $this->assertSame(1, Invoice::query()->count());
        // La page Paiements propose la facture ouverte de ce client pour le rattachement.
        $this->get(route('payments.index'))->assertSee('À rattacher à une facture')->assertSee('action="'.route('payments.attach', $waiting).'"', false)
            ->assertSee($invoice->number.' — reste 250,00')->assertSee('(trop petit)')->assertDontSee('Aucune facture envoyée à régler');

        // Trop gros pour le reste de la facture, facture d'un autre client, brouillon : refusés.
        $this->post(route('payments.attach', $waiting), ['invoice_id' => $invoice->id])->assertSessionHasErrors('attach_'.$waiting->id);
        $other = Client::factory()->create();
        $this->post(route('invoices.store'), ['client_id' => $other->id, 'due_days' => 30, 'lines' => [['type' => 'item', 'title' => 'Autre', 'quantity' => '1', 'unit_price' => '5000']]]);
        $foreign = Invoice::query()->latest('id')->first();
        $this->post(route('payments.attach', $waiting), ['invoice_id' => $foreign->id])->assertSessionHasErrors('attach_'.$waiting->id);
        $this->assertNull($waiting->fresh()->invoice_id);

        // Encaissement saisi par erreur : supprimable.
        $this->delete(route('payments.destroy', $waiting))->assertRedirect(route('payments.index').'#a-rattacher');
        $this->assertSame(0, Payment::query()->unallocated()->count());
        // Un client qui a des encaissements ne part pas à la corbeille.
        $this->delete(route('clients.destroy', $this->client))->assertSessionHasErrors('client');
    }

    public function test_card_link_for_an_existing_invoice_pays_that_invoice(): void
    {
        $invoice = $this->sentInvoice('350');
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => '400', 'mode' => 'card'])->assertSessionHasErrors('invoice_id');
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => '150', 'mode' => 'card'])->assertSessionHasNoErrors();
        $request = PaymentRequest::query()->sole();
        $this->assertSame([$invoice->id, 'Facture '.$invoice->number], [$request->invoice_id, $request->label]);
        auth()->logout();

        $this->get(route('portal.payment-request.pay', $request->token))->assertOk();
        $this->notify(OnlinePayment::query()->sole(), '150.00')->assertOk();
        $this->assertSame(['partial', 15000, 1], [$invoice->fresh()->status, $invoice->fresh()->amount_paid, Invoice::query()->count()]);

        // Facture soldée entre-temps : le paiement par carte est gardé « à rattacher », jamais perdu.
        $this->actingAs($this->admin());
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => '200', 'mode' => 'card'])->assertSessionHasNoErrors();
        $second = PaymentRequest::query()->latest('id')->first();
        $this->post(route('payments.store', $invoice), ['amount' => '200', 'paid_at' => today()->toDateString(), 'method' => 'especes']);
        auth()->logout();
        $this->get(route('portal.payment-request.pay', $second->token))->assertOk();
        $this->notify(OnlinePayment::query()->latest('id')->first(), '200.00', '778')->assertOk();
        $this->assertSame(['paid', 20000], [$invoice->fresh()->status, (int) Payment::query()->unallocated()->sum('amount')]);
    }

    public function test_seven_day_legal_delay_is_respected(): void
    {
        $quote = $this->acceptedQuote();
        $quote->forceFill(['signed_at' => now()->subDays(2), 'signed_on_site' => true])->save();

        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'label' => 'Acompte', 'amount' => '500', 'mode' => 'card'])
            ->assertSessionHasErrors(['invoice_id' => "Le devis {$quote->number} de ce client a été signé à domicile il y a moins de 7 jours : aucun paiement ne peut être reçu avant le 12/11/2026 (délai légal), sauf intervention urgente confirmée par le client."]);
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'label' => 'Autre', 'amount' => '500', 'mode' => 'received', 'paid_at' => '2026-11-06', 'method' => 'especes'])
            ->assertSessionHasErrors('invoice_id');
        $this->assertSame([0, 0], [Payment::query()->count(), PaymentRequest::query()->count()]);

        // Lien créé avant la signature : le client ne peut pas payer pendant le délai.
        $quote->forceFill(['signed_at' => null])->save();
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'label' => 'Acompte', 'amount' => '500', 'mode' => 'card'])->assertSessionHasNoErrors();
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

    public function test_commercial_cannot_collect_money(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('payments.requests.create'))->assertForbidden();
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id])->assertForbidden();
        $this->get(route('clients.show', $this->client))->assertOk()->assertDontSee(route('payments.requests.create', ['client' => $this->client->id]), false);
    }
}
