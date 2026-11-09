<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\User;
use App\Services\MyposGateway;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
        // Vrais paiements, avec un faux « myPOS » dont on détient la clé.
        $this->put(route('settings.payments'), ['enabled' => '1', 'package' => $package])->assertSessionHasNoErrors();

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

        $response = $this->get(route('portal.invoice.pay', $token))->assertOk()->assertSee('action="'.MyposGateway::URL.'"', false)->assertDontSee('Mode TEST');
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

        // Le lien d'une autre facture ne peut pas annuler cette tentative.
        $this->post(route('portal.invoice.pay-cancel', str_repeat('z', 48)), ['OrderID' => $attempt->order_id]);
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
        $this->get(route('settings.payments'))->assertSee('Faire un essai')->assertSee($this->invoice->publicUrl().'?essai=1');
        $this->put(route('settings.payments'), ['enabled' => '1'])->assertSessionHasErrors('package');

        // Désactivé : retour au lien manuel (ou rien).
        $this->put(route('settings.payments'), [])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('portal.invoice', $this->invoice->public_token))->assertDontSee('Payer par carte bancaire');
    }

    public function test_test_mode_is_hidden_from_clients_and_never_touches_the_invoice(): void
    {
        $token = $this->invoice->public_token;

        // Test : accès de test public de myPOS, bouton seulement avec le lien d'essai.
        $this->put(route('settings.payments'), ['enabled' => '1', 'test' => '1'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('portal.invoice', $token))->assertDontSee('Payer par carte bancaire');
        $this->get(route('portal.invoice.pay', $token))->assertRedirect(route('portal.invoice', $token));
        $this->get(route('portal.invoice', ['token' => $token, 'essai' => 1]))->assertSee('Payer par carte bancaire')
            ->assertSee(route('portal.invoice.pay', ['token' => $token, 'essai' => 1]), false);
        $response = $this->get(route('portal.invoice.pay', ['token' => $token, 'essai' => 1]))->assertOk()
            ->assertSee('action="'.MyposGateway::TEST_URL.'"', false)->assertSee('Mode TEST');
        $this->assertSame('000000000000010', $response->viewData('form')['fields']['SID']);

        // Notification d'un paiement d'essai (signée par notre faux myPOS en mode réel) : facture intacte.
        $this->actingAs($this->admin())->put(route('settings.payments'), ['enabled' => '1']);
        $attempt = OnlinePayment::query()->sole();
        $this->assertTrue($attempt->test);
        $this->post(route('portal.mypos.notify'), $this->signed([
            'IPCmethod' => 'IPCPurchaseNotify', 'Amount' => '350.00', 'Currency' => 'EUR', 'OrderID' => $attempt->order_id, 'IPC_Trnref' => '999',
        ]))->assertOk();
        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame(0, $this->invoice->payments()->count());
        $this->assertSame('sent', $this->invoice->fresh()->status);
    }

    public function test_connection_check_tells_whether_mypos_accepts_the_pack(): void
    {
        $this->get(route('settings.payments'))->assertSee('Tester la connexion avec myPOS');

        Http::fake([MyposGateway::URL => Http::sequence()
            ->push(['IPCmethod' => 'IPCGetTxnStatus', 'Status' => 2, 'StatusMsg' => 'Signature failed'])
            ->push(['IPCmethod' => 'IPCGetTxnStatus', 'Status' => 3, 'StatusMsg' => 'Order not found'])
            ->push('<html>Cloudflare</html>', 403)]);

        $this->post(route('settings.payments.check'))->assertRedirect(route('settings.payments'));
        $this->get(route('settings.payments'))->assertSee('Signature refusée par myPOS (boutique 000000000000123, clé n° 1)');

        $this->post(route('settings.payments.check'));
        $this->get(route('settings.payments'))->assertSee('Connexion réussie');

        $this->post(route('settings.payments.check'));
        $this->get(route('settings.payments'))->assertSee('Réponse inattendue de myPOS (code HTTP 403)');

        // Demande signée avec le pack enregistré, sans paiement.
        Http::assertSent(function ($request) {
            $data = $request->data();
            $signature = base64_decode($data['Signature']);
            unset($data['Signature']);
            $public = openssl_pkey_get_details(openssl_pkey_get_private(app(MyposGateway::class)->storedCredentials()['private_key']))['key'];

            return $request->url() === MyposGateway::URL && $data['IPCmethod'] === 'IPCGetTxnStatus' && ! isset($data['Amount'])
                && openssl_verify(base64_encode(implode('-', $data)), $signature, $public, OPENSSL_ALGO_SHA256) === 1;
        });
    }

    public function test_connection_check_without_pack_or_by_a_salesman(): void
    {
        $this->put(route('settings.payments'), ['enabled' => '1', 'test' => '1', 'forget' => '1'])->assertSessionHasNoErrors();
        $this->get(route('settings.payments'))->assertSee('Enregistrez d\'abord votre pack', false);
        Http::fake();
        $this->post(route('settings.payments.check'));
        $this->get(route('settings.payments'))->assertSee('Aucun pack de configuration enregistré');
        Http::assertNothingSent();

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->post(route('settings.payments.check'))->assertForbidden();
    }

    /**
     * Le formulaire envoyé à myPOS ne contient que les champs signés : le script de l'application
     * n'y ajoute rien (« _once » ajouté après la signature = « Error Code 2 » chez myPOS).
     */
    public function test_form_sent_to_mypos_contains_only_signed_fields(): void
    {
        $html = $this->get(route('portal.invoice.pay', $this->invoice->public_token))->assertOk()->getContent();
        $form = $this->get(route('portal.invoice.pay', $this->invoice->public_token))->viewData('form');

        preg_match('#<form method="POST" action="'.preg_quote(MyposGateway::URL, '#').'"[^>]*>(.*?)</form>#s', $html, $match);
        preg_match_all('#<input type="hidden" name="([^"]+)"#', $match[1] ?? '', $names);
        $this->assertSame(array_keys($form['fields']), $names[1]);
        $this->assertSame('Signature', end($names[1]));
        $this->assertStringNotContainsString('@csrf', $match[1]);
        $this->assertStringNotContainsString('_token', $match[1]);

        // Le script qui ajoute « _once » aux formulaires ignore ceux qui partent vers un autre site.
        $script = (string) file_get_contents(public_path('js/app.js'));
        $this->assertStringContainsString("if (!isPost(form) || isExternal(form) || form.querySelector('input[name=\"_once\"]')) { return; }", $script);
        $this->assertStringContainsString('.origin !== window.location.origin', $script);
    }
}
