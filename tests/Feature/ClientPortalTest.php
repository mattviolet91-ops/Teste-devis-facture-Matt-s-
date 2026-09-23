<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00');
        Mail::fake();
        $this->client = Client::factory()->create(['civility' => 'Mme', 'first_name' => 'Hélène', 'last_name' => 'Dupont', 'email' => 'helene@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sentQuote(): Quote
    {
        $this->actingAs($this->admin());
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
        $this->post(route('quotes.store'), [
            'client_id' => $this->client->id, 'title' => 'Traitement de toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '1500']],
        ]);
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->post(route('quotes.send', $quote));
        auth()->logout();

        return $quote->fresh();
    }

    /** Petite image PNG valide (signature). */
    private function signature(): string
    {
        $image = imagecreatetruecolor(300, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 10, 60, 290, 70, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    public function test_client_opens_the_quote_without_account_and_you_are_notified(): void
    {
        $quote = $this->sentQuote();
        $url = $quote->publicUrl();
        $this->assertMatchesRegularExpression('#/d/[A-Za-z0-9]{48}$#', $url);

        $this->get(route('portal.quote', $quote->public_token))->assertOk()
            ->assertSee('Devis DEV-2026-0001')->assertSee('Démoussage')->assertSee('J\'accepte le devis', false)
            ->assertDontSee('Se déconnecter');

        $this->assertNotNull($quote->fresh()->viewed_at);
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('mv.entreprise91@gmail.com') && str_contains($m->mailSubject, 'consulté'));

        $this->get(route('portal.quote.pdf', $quote->public_token))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_unknown_token_and_drafts_are_not_found(): void
    {
        $this->get('/d/'.str_repeat('a', 48))->assertNotFound();

        $this->actingAs($this->admin());
        $this->post(route('quotes.store'), [
            'client_id' => $this->client->id, 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'A', 'quantity' => '1', 'unit_price' => '10']],
        ]);
        $draft = Quote::query()->firstOrFail();
        $draft->publicUrl();
        auth()->logout();

        $this->get(route('portal.quote', $draft->fresh()->public_token))->assertNotFound();
    }

    public function test_client_signs_and_accepts_online(): void
    {
        $quote = $this->sentQuote();

        $this->post(route('portal.quote.sign', $quote->public_token), [
            'name' => 'Hélène Dupont', 'signature' => $this->signature(), 'agree' => '1',
        ], ['REMOTE_ADDR' => '203.0.113.7'])->assertSessionHasNoErrors()->assertRedirect(route('portal.quote', $quote->public_token));

        $quote->refresh();
        $this->assertSame('accepted', $quote->status);
        $this->assertSame('Hélène Dupont', $quote->signed_name);
        $this->assertSame('203.0.113.7', $quote->signed_ip);
        Storage::disk('local')->assertExists($quote->signature_path);
        $this->assertSame('client', $quote->client->fresh()->status);
        $this->assertSame(2, Snapshot::query()->where('document_id', $quote->id)->count(), 'PDF figé de nouveau avec la signature.');
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => str_contains($m->mailSubject, 'accepté'));

        $this->get(route('portal.quote', $quote->public_token))->assertSee('Devis accepté')->assertDontSee('J\'accepte le devis', false);
        $this->post(route('portal.quote.sign', $quote->public_token), ['name' => 'X', 'signature' => $this->signature(), 'agree' => '1'])->assertStatus(409);

        $this->actingAs($this->admin());
        $this->get(route('quotes.show', $quote))->assertSee('Signé en ligne par')->assertSee('Hélène Dupont');
        $this->get(route('quotes.signature', $quote))->assertOk();
    }

    public function test_signature_name_and_agreement_are_required(): void
    {
        $quote = $this->sentQuote();

        $this->post(route('portal.quote.sign', $quote->public_token), ['name' => '', 'signature' => '', 'agree' => ''])
            ->assertSessionHasErrors(['name', 'signature', 'agree']);
        $this->post(route('portal.quote.sign', $quote->public_token), ['name' => 'Hélène', 'signature' => 'data:image/png;base64,AAAA', 'agree' => '1'])
            ->assertSessionHasErrors('signature');

        $this->assertSame('sent', $quote->fresh()->status);
    }

    public function test_client_can_refuse_or_request_a_change(): void
    {
        $quote = $this->sentQuote();

        $this->post(route('portal.quote.change', $quote->public_token), ['comment' => 'Ajouter les gouttières'])->assertSessionHasNoErrors();
        $this->assertSame('Ajouter les gouttières', $quote->fresh()->client_comment);
        $this->assertSame('sent', $quote->fresh()->status);
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => str_contains($m->mailSubject, 'Demande de modification'));

        $this->post(route('portal.quote.refuse', $quote->public_token), ['comment' => 'Trop cher'])->assertSessionHasNoErrors();
        $this->assertSame('refused', $quote->fresh()->status);
        $this->assertStringContainsString('Trop cher', $quote->fresh()->refusal_reason);
    }

    public function test_expired_quote_cannot_be_signed(): void
    {
        $quote = $this->sentQuote();
        Carbon::setTestNow('2026-11-15 10:00');

        $this->get(route('portal.quote', $quote->public_token))->assertSee('Devis expiré');
        $this->post(route('portal.quote.sign', $quote->public_token), ['name' => 'X', 'signature' => $this->signature(), 'agree' => '1'])->assertStatus(409);
    }

    public function test_email_contains_the_client_link(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
        $this->post(route('quotes.store'), [
            'client_id' => $this->client->id, 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'A', 'quantity' => '1', 'unit_price' => '10']],
        ]);
        $quote = Quote::query()->firstOrFail();

        $this->get(route('emails.create', ['devis' => $quote->id]))->assertSee('{lien}');
        $this->post(route('emails.store', ['devis' => $quote->id]), [
            'to' => 'helene@example.com', 'subject' => 'Votre devis {numero}', 'body' => 'Acceptez en ligne : {lien}',
        ])->assertSessionHasNoErrors();

        $url = $quote->fresh()->publicUrl();
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => str_contains($m->text, $url));
        $this->get(route('quotes.show', $quote))->assertSee('Lien client')->assertSee($url);
    }

    public function test_invoice_link_shows_and_downloads_the_invoice(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('invoices.store'), [
            'client_id' => $this->client->id, 'due_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réparation', 'quantity' => '1', 'unit_price' => '250']],
        ]);
        $invoice = Invoice::query()->firstOrFail();
        $this->post(route('invoices.send', $invoice));
        $token = basename($invoice->fresh()->publicUrl());
        auth()->logout();

        $this->get(route('portal.invoice', $token))->assertOk()->assertSee('Facture FAC-2026-0001')->assertSee('Réparation');
        $this->get(route('portal.invoice.pdf', $token))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertNotNull($invoice->fresh()->viewed_at);
    }
}
