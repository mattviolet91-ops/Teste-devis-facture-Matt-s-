<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Services\MyposGateway;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class MyposTest extends TestCase
{
    use RefreshDatabase;

    /** Clé privée qui joue le rôle de myPOS (signature des notifications). */
    private string $myposKey = '';

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00');
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
        $this->put(route('settings.payments'), ['enabled' => '1', 'test' => '1', 'package' => $package])->assertSessionHasNoErrors();

        $client = Client::factory()->create(['last_name' => 'Petit', 'email' => 'petit@example.com']);
        $this->post(route('invoices.store'), [
            'client_id' => $client->id, 'due_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réparation', 'quantity' => '1', 'unit_price' => '350']],
        ]);
        $this->invoice = Invoice::query()->firstOrFail();
        $this->post(route('invoices.send', $this->invoice));
        $this->invoice->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Message signé comme le ferait myPOS. */
    private function signed(array $fields): array
    {
        openssl_sign(base64_encode(implode('-', $fields)), $signature, $this->myposKey, OPENSSL_ALGO_SHA256);

        return $fields + ['Signature' => base64_encode($signature)];
    }

    public function test_client_pays_by_card_and_invoice_is_marked_paid(): void
    {
        $token = $this->invoice->public_token;
        $this->get(route('portal.invoice', $token))->assertSee('Payer par carte bancaire')->assertSee(route('portal.invoice.pay', $token), false);

        $response = $this->get(route('portal.invoice.pay', $token))->assertOk()->assertSee(MyposGateway::TEST_URL, false)->assertSee('Mode TEST');
        $form = $response->viewData('form');
        $this->assertSame('350.00', $form['fields']['Amount']);
        $this->assertSame(route('portal.mypos.notify'), $form['fields']['URL_Notify']);

        // Notre signature est vérifiable avec notre clé publique.
        $cred = app(MyposGateway::class)->credentials();
        $fields = $form['fields'];
        $signature = base64_decode(array_pop($fields));
        $public = openssl_pkey_get_details(openssl_pkey_get_private($cred['private_key']))['key'];
        $this->assertSame(1, openssl_verify(base64_encode(implode('-', $fields)), $signature, $public, OPENSSL_ALGO_SHA256));

        $attempt = OnlinePayment::query()->sole();
        $notify = $this->signed([
            'IPCmethod' => 'IPCPurchaseNotify', 'SID' => '000000000000123', 'Amount' => '350.00', 'Currency' => 'EUR',
            'OrderID' => $attempt->order_id, 'IPC_Trnref' => '1234567', 'RequestSTAN' => '42', 'RequestDateTime' => '2026-10-05 10:01:00',
        ]);

        auth()->logout();
        $this->post(route('portal.mypos.notify'), $notify)->assertOk()->assertSee('OK');
        // Même notification renvoyée : pas de doublon.
        $this->post(route('portal.mypos.notify'), $notify)->assertOk();

        $invoice = $this->invoice->fresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(1, $invoice->payments()->count());
        $payment = $invoice->payments()->first();
        $this->assertSame(['mypos', 35000, '1234567'], [$payment->method, $payment->amount, $payment->reference]);
        $this->assertSame('paid', $attempt->fresh()->status);
        Mail::assertNothingSent(); // Gmail non configuré ici : notification par le téléphone uniquement.

        $this->post(route('portal.invoice.paid', $token))->assertRedirect(route('portal.invoice', $token));
        $this->get(route('portal.invoice', $token))->assertSee('Facture réglée')->assertDontSee('Payer par carte bancaire');
        $this->get(route('portal.invoice.pay', $token))->assertRedirect(route('portal.invoice', $token));
    }

    public function test_forged_or_unknown_notifications_are_refused(): void
    {
        $this->get(route('portal.invoice.pay', $this->invoice->public_token));
        $attempt = OnlinePayment::query()->sole();
        $fields = ['IPCmethod' => 'IPCPurchaseNotify', 'Amount' => '350.00', 'OrderID' => $attempt->order_id, 'IPC_Trnref' => '1'];

        $this->post(route('portal.mypos.notify'), $fields + ['Signature' => base64_encode('faux')])->assertStatus(400);
        $forged = $this->signed($fields);
        $forged['Amount'] = '1.00';
        $this->post(route('portal.mypos.notify'), $forged)->assertStatus(400);
        $this->post(route('portal.mypos.notify'), $this->signed(['OrderID' => 'INCONNU'] + $fields))->assertStatus(400);

        $this->assertSame(0, $this->invoice->payments()->count());
        $this->assertSame('pending', $attempt->fresh()->status);

        $this->post(route('portal.invoice.pay-cancel', $this->invoice->public_token), ['OrderID' => $attempt->order_id])
            ->assertRedirect(route('portal.invoice', $this->invoice->public_token));
        $this->assertSame('cancelled', $attempt->fresh()->status);
    }

    public function test_settings_never_show_the_package_and_real_mode_requires_one(): void
    {
        $this->get(route('settings.payments'))->assertOk()->assertSee('Enregistré (boutique 000000000000123)')->assertDontSee('BEGIN');
        $this->assertStringNotContainsString('BEGIN', (string) app(Settings::class)->get('mypos.package'));

        $this->put(route('settings.payments'), ['enabled' => '1', 'package' => 'nimportequoi'])->assertSessionHasErrors('package');
        $this->put(route('settings.payments'), ['enabled' => '1', 'test' => '1', 'forget' => '1'])->assertSessionHasNoErrors();
        $this->put(route('settings.payments'), ['enabled' => '1'])->assertSessionHasErrors('package');

        // Désactivé : retour au lien manuel (ou rien).
        $this->put(route('settings.payments'), [])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('portal.invoice', $this->invoice->public_token))->assertDontSee('Payer par carte bancaire');
    }
}
