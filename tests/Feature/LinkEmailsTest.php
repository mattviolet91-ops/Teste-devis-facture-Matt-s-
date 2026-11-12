<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\PaymentRequest;
use App\Models\SentEmail;
use App\Models\User;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** Emails des liens (paiement, signature), facture après paiement, relances et liste de l'accueil. */
class LinkEmailsTest extends TestCase
{
    use RefreshDatabase;

    private string $myposKey = '';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00');
        Mail::fake();
        Storage::fake('local');
        $this->app->instance(PushService::class, Mockery::mock(PushService::class)->shouldIgnoreMissing());

        $merchant = openssl_pkey_new(['private_key_bits' => 1024]);
        openssl_pkey_export($merchant, $merchantPem);
        $mypos = openssl_pkey_new(['private_key_bits' => 1024]);
        openssl_pkey_export($mypos, $this->myposKey);
        $package = base64_encode(json_encode(['sid' => '000000000000123', 'cn' => '61938166610', 'pk' => $merchantPem, 'pc' => openssl_pkey_get_details($mypos)['key'], 'idx' => 1]));

        $this->actingAs($this->admin());
        $this->put(route('settings.payments'), ['enabled' => '1', 'email_receipt' => '1', 'package' => $package])->assertSessionHasNoErrors();
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcd efgh ijkl mnop'])->assertSessionHasNoErrors();
        $this->client = Client::factory()->create(['last_name' => 'Petit', 'email' => 'petit@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function notify(OnlinePayment $attempt, string $amount): void
    {
        $fields = ['IPCmethod' => 'IPCPurchaseNotify', 'SID' => '000000000000123', 'Amount' => $amount, 'Currency' => 'EUR', 'OrderID' => $attempt->order_id, 'IPC_Trnref' => 'T'.$attempt->id];
        openssl_sign(base64_encode(implode('-', $fields)), $signature, $this->myposKey, OPENSSL_ALGO_SHA256);
        $this->post(route('portal.mypos.notify'), $fields + ['Signature' => base64_encode($signature)])->assertOk();
    }

    private function paymentRequest(): PaymentRequest
    {
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'label' => 'Dépannage fuite', 'amount' => '180', 'mode' => 'card'])->assertSessionHasNoErrors();

        return PaymentRequest::query()->latest('id')->firstOrFail();
    }

    private function documentToSign(): Attachment
    {
        $this->post(route('attachments.store', $this->client), ['files' => [UploadedFile::fake()->image('bon.jpg', 600, 800)]]);
        $doc = Attachment::query()->latest('id')->firstOrFail();
        $this->post(route('attachments.sign.request', $doc))->assertSessionHasNoErrors();

        return $doc->fresh();
    }

    public function test_payment_link_and_document_to_sign_are_sent_by_email(): void
    {
        $request = $this->paymentRequest();
        $this->get(route('payments.requests.show', $request))->assertSee('Envoyer le lien par email')->assertSee('petit@example.com');
        $this->post(route('payments.requests.email', $request), ['to' => 'pas-une-adresse'])->assertSessionHasErrors('to');
        $this->post(route('payments.requests.email', $request), ['to' => 'petit@example.com', 'message' => 'Bonjour, voici le lien.'])->assertSessionHasNoErrors();
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('petit@example.com') && $m->buttonUrl === $request->publicUrl() && str_contains($m->mailSubject, 'Dépannage fuite'));

        $doc = $this->documentToSign();
        $this->get(route('clients.show', $this->client))->assertSee('Envoyer par email');
        $this->post(route('attachments.sign.email', $doc), ['to' => 'petit@example.com'])->assertRedirect(route('clients.show', $this->client).'#documents')->assertSessionHasNoErrors();
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->buttonUrl === $doc->publicUrl() && $m->buttonLabel === 'Lire et signer le document');
        $this->assertSame(2, SentEmail::query()->where('client_id', $this->client->id)->where('status', 'sent')->count());

        // Lien annulé ou document signé : plus d'envoi.
        $this->delete(route('payments.requests.cancel', $request));
        $this->post(route('payments.requests.email', $request), ['to' => 'petit@example.com'])->assertStatus(409);
    }

    public function test_client_gets_a_receipt_or_the_invoice_by_email_after_a_card_payment(): void
    {
        // Montant libre sans facture : un simple reçu, sans pièce jointe.
        $request = $this->paymentRequest();
        auth()->logout();
        $this->get(route('portal.payment-request.pay', $request->token))->assertOk();
        $this->notify(OnlinePayment::query()->sole(), '180.00');
        $this->assertSame(0, Invoice::query()->count());
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('petit@example.com') && str_starts_with($m->mailSubject, 'Reçu de paiement') && $m->pdfName === null);

        // Lien pour une facture existante : la facture est envoyée, PDF joint.
        Mail::fake();
        $this->actingAs($this->admin());
        $this->post(route('invoices.store'), ['client_id' => $this->client->id, 'due_days' => 30, 'lines' => [['type' => 'item', 'title' => 'Réparation', 'quantity' => '1', 'unit_price' => '350']]]);
        $invoice = Invoice::query()->sole();
        $this->post(route('invoices.send', $invoice));
        $this->post(route('payments.requests.store'), ['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'amount' => '350', 'mode' => 'card'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('portal.payment-request.pay', PaymentRequest::query()->latest('id')->first()->token));
        $this->notify(OnlinePayment::query()->latest('id')->first(), '350.00');
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('petit@example.com') && str_contains($m->mailSubject, $invoice->fresh()->number) && $m->pdfName !== null);

        // Réglage décoché : pas d'email au client.
        Mail::fake();
        app(Settings::class)->set(['mypos.email_receipt' => false]);
        $this->actingAs($this->admin());
        $third = $this->paymentRequest();
        auth()->logout();
        $this->get(route('portal.payment-request.pay', $third->token));
        $this->notify(OnlinePayment::query()->latest('id')->first(), '180.00');
        Mail::assertNotSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('petit@example.com'));
    }

    public function test_automatic_reminders_are_off_by_default_then_sent_twice_at_most(): void
    {
        $request = $this->paymentRequest();
        $doc = $this->documentToSign();
        $noEmail = Client::factory()->create(['email' => null]);
        $this->post(route('payments.requests.store'), ['client_id' => $noEmail->id, 'label' => 'Sans email', 'amount' => '50', 'mode' => 'card']);

        Carbon::setTestNow('2026-11-14 10:45');
        $this->artisan('app:pending-reminders')->expectsOutputToContain('désactivée')->assertSuccessful();
        Mail::assertNothingSent();

        $this->put(route('settings.emails.pending-reminders'), ['pending_auto' => '1', 'pending_days' => '3'])->assertSessionHasNoErrors();
        $this->artisan('app:pending-reminders')->expectsOutputToContain('2 relance(s)')->assertSuccessful();
        Mail::assertSent(ClientMessage::class, 2);
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => str_starts_with($m->mailSubject, 'Rappel : ') && $m->buttonUrl === $doc->publicUrl());

        // Le lendemain : trop tôt. Trois jours après : 2e et dernière relance. Ensuite plus rien.
        Carbon::setTestNow('2026-11-15 10:45');
        $this->artisan('app:pending-reminders')->expectsOutputToContain('0 relance(s)');
        Carbon::setTestNow('2026-11-17 10:45');
        $this->artisan('app:pending-reminders')->expectsOutputToContain('2 relance(s)');
        Carbon::setTestNow('2026-11-25 10:45');
        $this->artisan('app:pending-reminders')->expectsOutputToContain('0 relance(s)');
        Mail::assertSent(ClientMessage::class, 4);
        $this->assertSame([2, 2], [$request->fresh()->reminder_count, $doc->fresh()->reminder_count]);
    }

    public function test_home_lists_documents_to_sign_and_payment_links(): void
    {
        $this->paymentRequest();
        $this->documentToSign();

        $this->get(route('dashboard'))->assertSee('Documents à faire signer')->assertSee('bon.jpg')->assertSee('Liens de paiement en attente')->assertSee('Dépannage fuite');

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('dashboard'))->assertOk()->assertSee('Documents à faire signer')->assertDontSee('Liens de paiement en attente');
    }
}
