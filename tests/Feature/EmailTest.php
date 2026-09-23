<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\SentEmail;
use App\Services\MailSettings;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28 10:00');
        Mail::fake();
        $this->client = Client::factory()->create([
            'civility' => 'Mme', 'first_name' => 'Hélène', 'last_name' => 'Dupont', 'email' => 'helene@example.com',
        ]);
        $this->actingAs($this->admin());
        $this->configureGmail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function configureGmail(): void
    {
        $this->put(route('settings.emails'), [
            'username' => 'mv.entreprise91@gmail.com', 'password' => 'abcd efgh ijkl mnop', 'bcc_self' => '1',
        ])->assertSessionHasNoErrors();
    }

    private function quote(): Quote
    {
        $this->post(route('quotes.store'), [
            'client_id' => $this->client->id, 'title' => 'Traitement de toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '1500']],
        ])->assertSessionHasNoErrors();

        return Quote::query()->latest('id')->firstOrFail();
    }

    public function test_default_templates_are_installed(): void
    {
        $this->assertSame(6, EmailTemplate::query()->count());
        $this->assertSame('Envoi du devis', EmailTemplate::query()->for('quote')->first()->name);
        $this->assertSame('Envoi de la facture', EmailTemplate::query()->for('invoice')->first()->name);
    }

    public function test_gmail_password_is_stored_encrypted_and_never_displayed(): void
    {
        $stored = app(Settings::class)->get('mail.password');

        $this->assertNotSame('abcdefghijklmnop', $stored);
        $this->assertSame('abcdefghijklmnop', Crypt::decryptString($stored), 'Espaces retirés.');
        $this->assertTrue(app(MailSettings::class)->isConfigured());
        $this->get(route('settings.emails'))->assertOk()->assertSee('Configuré')->assertDontSee('abcdefghijklmnop');

        // Laisser le champ vide garde le mot de passe.
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com']);
        $this->assertSame($stored, app(Settings::class)->get('mail.password'));

        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'forget_password' => '1']);
        $this->assertFalse(app(MailSettings::class)->hasPassword());
    }

    public function test_gmail_is_applied_to_the_mail_configuration(): void
    {
        app(MailSettings::class)->apply();

        $this->assertSame('gmail', config('mail.default'));
        $this->assertSame('smtp.gmail.com', config('mail.mailers.gmail.host'));
        $this->assertSame('abcdefghijklmnop', config('mail.mailers.gmail.password'));
        $this->assertSame("Matt's Couverture", config('mail.from.name'));
    }

    public function test_compose_screen_fills_template_variables(): void
    {
        $quote = $this->quote();

        $this->get(route('emails.create', ['devis' => $quote->id]))->assertOk()
            ->assertSee('Envoi du devis')
            ->assertSee('Bonjour Madame Dupont,')
            ->assertSee('pour traitement de toiture', false)
            ->assertSee('helene@example.com')
            ->assertSee('brouillon');
    }

    public function test_sending_a_draft_quote_numbers_it_and_attaches_the_pdf(): void
    {
        $quote = $this->quote();

        $this->post(route('emails.store', ['devis' => $quote->id]), [
            'to' => 'helene@example.com', 'subject' => 'Votre devis {numero}', 'body' => "Bonjour,\nVoici le devis {numero}.", 'attach_pdf' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('quotes.show', $quote));

        $quote->refresh();
        $this->assertSame('sent', $quote->status);
        $this->assertSame('DEV-2026-0001', $quote->number);

        Mail::assertSent(ClientMessage::class, function (ClientMessage $mail) {
            return $mail->hasTo('helene@example.com')
                && $mail->hasBcc('mv.entreprise91@gmail.com')
                && $mail->mailSubject === 'Votre devis DEV-2026-0001'
                && str_contains($mail->text, 'Voici le devis DEV-2026-0001.')
                && str_starts_with((string) $mail->pdf, '%PDF-')
                && $mail->pdfName === 'Devis DEV-2026-0001 - Mme Helene Dupont.pdf';
        });

        $log = SentEmail::query()->sole();
        $this->assertTrue($log->isSent());
        $this->assertSame($quote->id, $log->document_id);
        $this->assertDatabaseHas('activity_log', ['action' => 'email.sent', 'subject_id' => $quote->id]);
        $this->get(route('emails.index'))->assertSee('Votre devis DEV-2026-0001')->assertSee('PDF joint');
        $this->get(route('emails.show', $log))->assertOk()->assertSee('Voici le devis DEV-2026-0001.');
    }

    public function test_invoice_email_uses_invoice_template(): void
    {
        $quote = $this->quote();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $this->post(route('quotes.invoice', $quote), ['kind' => 'deposit', 'percent' => '40']);
        $invoice = Invoice::query()->firstOrFail();

        $this->get(route('emails.create', ['facture' => $invoice->id]))->assertOk()
            ->assertSee('Facture d&#039;acompte', false)
            ->assertSee('600,00 €')
            ->assertSee('payable à réception');
    }

    public function test_free_email_to_a_client_without_attachment(): void
    {
        $this->get(route('emails.create', ['client' => $this->client->id]))->assertOk()->assertSee('Message libre')->assertDontSee('Joindre le PDF');

        $this->post(route('emails.store', ['client' => $this->client->id]), [
            'to' => 'helene@example.com, autre@example.com', 'cc' => 'copie@example.com', 'subject' => 'Rendez-vous', 'body' => 'Bonjour',
        ])->assertRedirect(route('clients.show', $this->client));

        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('autre@example.com') && $m->hasCc('copie@example.com') && $m->pdf === null);
    }

    public function test_invalid_address_and_missing_configuration_are_refused(): void
    {
        $this->post(route('emails.store', ['client' => $this->client->id]), ['to' => 'pas-une-adresse', 'subject' => 'A', 'body' => 'B'])
            ->assertSessionHasErrors('to');

        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'forget_password' => '1']);
        $this->post(route('emails.store', ['client' => $this->client->id]), ['to' => 'helene@example.com', 'subject' => 'A', 'body' => 'B'])
            ->assertSessionHasErrors('to');

        Mail::assertNothingSent();
        $this->get(route('emails.create', ['client' => $this->client->id]))->assertSee('pas encore configuré')->assertSee('Ouvrir dans ma messagerie');
    }

    public function test_templates_can_be_managed(): void
    {
        $this->get(route('settings.emails'))->assertOk()->assertSee('{salutation}')->assertSee('Relance de paiement');

        $this->post(route('settings.emails.templates.store'), [
            'name' => 'Devis après visite', 'context' => 'quote', 'subject' => 'Suite à ma visite', 'body' => '{salutation}', 'is_default' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Devis après visite', EmailTemplate::query()->for('quote')->first()->name);
        $this->assertSame(1, EmailTemplate::query()->where('context', 'quote')->where('is_default', true)->count());

        $template = EmailTemplate::query()->where('name', 'Devis après visite')->first();
        $this->delete(route('settings.emails.templates.destroy', $template));
        $this->assertModelMissing($template);
    }

    public function test_test_email_is_sent_to_company_address(): void
    {
        $this->post(route('settings.emails.test'))->assertSessionHasNoErrors();

        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('mv.entreprise91@gmail.com'));
    }

    public function test_email_pages_require_login(): void
    {
        auth()->logout();
        $this->get(route('emails.index'))->assertRedirect(route('login'));
        $this->get(route('emails.create', ['client' => $this->client->id]))->assertRedirect(route('login'));
    }
}
