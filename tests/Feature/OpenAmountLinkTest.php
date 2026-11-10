<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\PaymentRequest;
use App\Models\Quote;
use App\Services\MyposGateway;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/** Lien permanent « montant libre » : le client saisit son nom, le motif et le montant. */
class OpenAmountLinkTest extends TestCase
{
    use RefreshDatabase;

    private string $myposKey = '';

    private string $code = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00');
        Mail::fake();
        $this->app->instance(PushService::class, Mockery::mock(PushService::class)->shouldIgnoreMissing());

        $merchant = openssl_pkey_new(['private_key_bits' => 1024]);
        openssl_pkey_export($merchant, $merchantPem);
        $mypos = openssl_pkey_new(['private_key_bits' => 1024]);
        openssl_pkey_export($mypos, $this->myposKey);
        $package = base64_encode(json_encode(['sid' => '000000000000123', 'cn' => '61938166610', 'pk' => $merchantPem, 'pc' => openssl_pkey_get_details($mypos)['key'], 'idx' => 1]));

        $this->actingAs($this->admin());
        $this->put(route('settings.payments'), ['enabled' => '1', 'email_receipt' => '1', 'free_link' => '1', 'free_min' => '5', 'free_max' => '2000', 'package' => $package])->assertSessionHasNoErrors();
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcd efgh ijkl mnop'])->assertSessionHasNoErrors();
        $this->code = basename(app(MyposGateway::class)->freeLinkUrl());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payer(array $extra = []): array
    {
        return array_replace(['first_name' => 'Julie', 'last_name' => 'Morel', 'email' => 'Julie.Morel@example.com', 'phone' => '0611223344',
            'label' => 'Acompte gouttières', 'amount' => '250', 'agree' => '1'], $extra);
    }

    private function notify(OnlinePayment $attempt, string $amount): void
    {
        $fields = ['IPCmethod' => 'IPCPurchaseNotify', 'SID' => '000000000000123', 'Amount' => $amount, 'Currency' => 'EUR', 'OrderID' => $attempt->order_id, 'IPC_Trnref' => 'T'.$attempt->id];
        openssl_sign(base64_encode(implode('-', $fields)), $signature, $this->myposKey, OPENSSL_ALGO_SHA256);
        $this->post(route('portal.mypos.notify'), $fields + ['Signature' => base64_encode($signature)])->assertOk();
    }

    public function test_client_pays_an_amount_of_their_choice_and_gets_an_invoice(): void
    {
        $this->get(route('payments.index'))->assertSee('Lien de paiement à montant libre')->assertSee('/payer/'.$this->code);
        auth()->logout();

        $this->get(route('portal.pay-open', $this->code))->assertOk()->assertSee('Montant à payer')->assertSee('Entre 5,00');
        $this->post(route('portal.pay-open.start', $this->code), $this->payer(['amount' => '3']))->assertSessionHasErrors('amount');
        $this->post(route('portal.pay-open.start', $this->code), $this->payer(['amount' => '5000']))->assertSessionHasErrors('amount');
        $this->post(route('portal.pay-open.start', $this->code), $this->payer(['agree' => null]))->assertSessionHasErrors('agree');
        $this->post(route('portal.pay-open.start', $this->code), $this->payer(['site_web' => 'http://spam']))->assertRedirect();
        $this->assertSame(0, PaymentRequest::query()->count());

        $form = $this->post(route('portal.pay-open.start', $this->code), $this->payer())->assertOk()->viewData('form');
        $this->assertSame(['250.00', 'julie.morel@example.com', 'Acompte gouttieres'], [$form['fields']['Amount'], $form['fields']['customeremail'], $form['fields']['Note']]);
        $this->assertSame(route('portal.pay-open.thanks', $this->code), $form['fields']['URL_OK']);
        // Rien n'est créé tant que le paiement n'est pas confirmé.
        $this->assertSame([0, 0], [Client::query()->count(), Invoice::query()->count()]);

        $this->notify(OnlinePayment::query()->sole(), '250.00');

        $client = Client::query()->sole();
        $this->assertSame(['Julie', 'Morel', 'julie.morel@example.com', 'client'], [$client->first_name, $client->last_name, $client->email, $client->status]);
        $invoice = Invoice::query()->sole();
        $this->assertSame(['standard', 'paid', 25000, $client->id], [$invoice->kind, $invoice->status, $invoice->total_ttc, $invoice->client_id]);
        $this->assertSame(['paid', $client->id], [PaymentRequest::query()->sole()->status, PaymentRequest::query()->sole()->client_id]);
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('julie.morel@example.com') && $m->pdfName !== null);
        $this->post(route('portal.pay-open.thanks', $this->code))->assertOk()->assertSee('Merci');

        // Même email une seconde fois : la fiche existante est reprise.
        $this->post(route('portal.pay-open.start', $this->code), $this->payer(['first_name' => 'J.', 'label' => 'Solde gouttières', 'amount' => '100,50']))->assertOk();
        $this->notify(OnlinePayment::query()->latest('id')->first(), '100.50');
        $this->assertSame([1, 2], [Client::query()->count(), Invoice::query()->where('client_id', $client->id)->count()]);
    }

    public function test_link_needs_the_right_code_and_can_be_renewed_or_disabled(): void
    {
        auth()->logout();
        $this->get('/payer/'.str_repeat('a', 24))->assertNotFound();
        $this->post('/payer/'.str_repeat('a', 24), $this->payer())->assertNotFound();

        $this->actingAs($this->admin());
        $this->put(route('settings.payments'), ['enabled' => '1', 'free_link' => '1', 'free_min' => '5', 'free_max' => '2000', 'free_renew' => '1'])->assertSessionHasNoErrors();
        $new = basename(app(MyposGateway::class)->freeLinkUrl());
        $this->assertNotSame($this->code, $new);
        $this->get(route('portal.pay-open', $this->code))->assertNotFound();
        $this->get(route('portal.pay-open', $new))->assertOk();

        $this->put(route('settings.payments'), ['enabled' => '1', 'free_min' => '5', 'free_max' => '2000'])->assertSessionHasNoErrors();
        $this->get(route('portal.pay-open', $new))->assertNotFound();
        $this->get(route('payments.index'))->assertDontSee('Lien de paiement à montant libre');
    }

    public function test_known_client_inside_the_seven_day_delay_cannot_pay_and_abandoned_attempts_are_cleaned(): void
    {
        $client = Client::factory()->create(['email' => 'julie.morel@example.com']);
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => 'Toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réfection', 'quantity' => '1', 'unit_price' => '3000']]]);
        $quote = Quote::query()->sole();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $quote->forceFill(['signed_at' => now()->subDays(2), 'signed_on_site' => true])->save();
        auth()->logout();

        $this->post(route('portal.pay-open.start', $this->code), $this->payer())->assertSessionHasErrors(['amount' => 'Le paiement sera possible à l\'issue du délai légal de 7 jours après la signature de votre devis. Nous vous enverrons la facture à ce moment-là.']);
        $this->assertSame(0, PaymentRequest::query()->count());

        // Paiement commencé puis abandonné par un inconnu : supprimé par le nettoyage nocturne.
        $this->post(route('portal.pay-open.start', $this->code), $this->payer(['email' => 'inconnu@example.com']))->assertOk();
        $this->assertSame(1, PaymentRequest::query()->whereNull('client_id')->count());
        Carbon::setTestNow('2026-11-13 03:00');
        $this->artisan('app:purge-trash')->assertSuccessful();
        $this->assertSame(0, PaymentRequest::query()->count());
    }
}
