<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PdfService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 10:00');
        Mail::fake();
        $this->client = Client::factory()->create(['email' => 'client@example.com']);
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sentInvoice(string $price = '1000', int $dueDays = 0): Invoice
    {
        $this->post(route('invoices.store'), [
            'client_id' => $this->client->id, 'due_days' => $dueDays,
            'lines' => [['type' => 'item', 'title' => 'Réparation', 'quantity' => '1', 'unit_price' => $price]],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->post(route('invoices.send', $invoice));

        return $invoice->fresh();
    }

    public function test_partial_then_full_payment_updates_status(): void
    {
        $invoice = $this->sentInvoice();

        $this->post(route('payments.store', $invoice), ['amount' => '400', 'paid_at' => '2026-10-01', 'method' => 'cheque', 'reference' => '1234567'])
            ->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame('partial', $invoice->status);
        $this->assertSame(40000, $invoice->amount_paid);
        $this->assertSame(60000, $invoice->balance());
        $this->get(route('invoices.show', $invoice))->assertSee('Partiellement payée')->assertSee('réf. 1234567');

        $this->post(route('payments.store', $invoice), ['amount' => '600', 'paid_at' => '2026-10-01', 'method' => 'virement'])->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('2026-10-01', $invoice->paid_at->toDateString());
        $this->assertFalse($invoice->isCorrectable(), 'Une facture payée ne se modifie plus.');

        $this->get(route('dashboard'))->assertSeeInOrder(['Montant à encaisser', "0,00\u{00A0}€"], false);
    }

    public function test_payment_cannot_exceed_balance_nor_be_in_the_future(): void
    {
        $invoice = $this->sentInvoice();

        $this->post(route('payments.store', $invoice), ['amount' => '1500', 'paid_at' => '2026-10-01', 'method' => 'especes'])->assertSessionHasErrors('amount');
        $this->post(route('payments.store', $invoice), ['amount' => '100', 'paid_at' => '2026-10-05', 'method' => 'especes'])->assertSessionHasErrors('paid_at');
        $this->post(route('payments.store', $invoice), ['amount' => '100', 'paid_at' => '2026-10-01', 'method' => 'autre'])->assertSessionHasErrors('method_detail');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_deleting_a_payment_restores_the_balance(): void
    {
        $invoice = $this->sentInvoice();
        $this->post(route('payments.store', $invoice), ['amount' => '1000', 'paid_at' => '2026-10-01', 'method' => 'cb']);
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->delete(route('payments.destroy', Payment::query()->sole()));
        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->amount_paid);
    }

    public function test_payments_follow_the_corrected_invoice(): void
    {
        $invoice = $this->sentInvoice();
        $this->post(route('payments.store', $invoice), ['amount' => '300', 'paid_at' => '2026-10-01', 'method' => 'virement']);

        $this->post(route('invoices.correct', $invoice));
        $draft = Invoice::query()->where('corrects_id', $invoice->id)->firstOrFail();
        $this->post(route('invoices.send', $draft));

        $this->assertSame($draft->id, Payment::query()->sole()->invoice_id);
        $this->assertSame('partial', $draft->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->amount_paid);
    }

    public function test_payments_page_lists_totals_by_method(): void
    {
        $invoice = $this->sentInvoice();
        $this->post(route('payments.store', $invoice), ['amount' => '250', 'paid_at' => '2026-10-01', 'method' => 'mypos']);

        $this->get(route('payments.index'))->assertOk()->assertSee('myPOS')->assertSee("250,00\u{00A0}€", false)->assertSee($invoice->number);
    }

    public function test_manual_reminder_is_counted(): void
    {
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
        $invoice = $this->sentInvoice('500', 15);

        $this->get(route('emails.create', ['facture' => $invoice->id, 'relance' => 1]))->assertOk()->assertSee('Rappel : facture n°', false);
        $this->post(route('emails.store', ['facture' => $invoice->id]), [
            'to' => 'client@example.com', 'subject' => 'Rappel', 'body' => 'Merci de régler {lien}', 'reminder' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $invoice->fresh()->reminder_count);
        $this->get(route('invoices.show', $invoice))->assertSee('Relancer (1)');
    }

    public function test_automatic_reminders_respect_delay_interval_and_maximum(): void
    {
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
        $invoice = $this->sentInvoice('500', 0);

        $this->artisan('app:payment-reminders')->expectsOutputToContain('désactivées');
        app(Settings::class)->set(['reminders.auto_enabled' => true, 'reminders.first_after_days' => 3, 'reminders.repeat_days' => 7, 'reminders.max' => 2]);

        Carbon::setTestNow('2026-10-03 09:00');
        $this->artisan('app:payment-reminders')->expectsOutputToContain('0 relance');

        Carbon::setTestNow('2026-10-04 09:00');
        $this->artisan('app:payment-reminders')->expectsOutputToContain('1 relance');
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('client@example.com') && str_contains($m->mailSubject, 'Rappel'));

        Carbon::setTestNow('2026-10-08 09:00');
        $this->artisan('app:payment-reminders')->expectsOutputToContain('0 relance');
        Carbon::setTestNow('2026-10-11 09:00');
        $this->artisan('app:payment-reminders')->expectsOutputToContain('1 relance');
        Carbon::setTestNow('2026-10-20 09:00');
        $this->artisan('app:payment-reminders')->expectsOutputToContain('0 relance');

        $this->assertSame(2, $invoice->fresh()->reminder_count);
    }

    public function test_paid_invoice_can_be_deleted_and_no_longer_counts_in_revenue(): void
    {
        $invoice = $this->sentInvoice('1000');
        $this->post(route('payments.store', $invoice), ['amount' => '1000', 'paid_at' => '2026-10-01', 'method' => 'virement']);
        $this->get(route('dashboard'))->assertSeeInOrder(['CA facturé (HT)', "1\u{202F}000,00\u{00A0}€"], false);

        Carbon::setTestNow('2026-11-05 10:00');
        $this->get(route('invoices.show', $invoice))->assertSee('Supprimer la facture');
        $this->post(route('invoices.cancel', $invoice))->assertRedirect(route('invoices.index'));

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->get(route('dashboard', ['periode' => 'annee']))
            ->assertSeeInOrder(['CA facturé (HT)', "0,00\u{00A0}€"], false)
            ->assertSeeInOrder(['Encaissé', "0,00\u{00A0}€"], false)
            ->assertSeeInOrder(['Montant à encaisser', "0,00\u{00A0}€"], false);
        $this->get(route('payments.index', ['du' => '2026-10-01', 'au' => '2026-11-05']))->assertSee('Aucun paiement sur cette période');
        $this->get(route('dashboard', ['periode' => 'mois']))->assertSeeInOrder(['CA facturé (HT)', "0,00\u{00A0}€"], false);
    }

    public function test_same_payment_form_sent_twice_is_recorded_once(): void
    {
        $invoice = $this->sentInvoice();
        $form = ['amount' => '400', 'paid_at' => '2026-10-01', 'method' => 'cheque', '_once' => 'f0e1d2c3-b4a5-4697-8899-aabbccddeeff'];

        // Double appui, ou réponse perdue puis renvoi : un seul paiement.
        $first = $this->post(route('payments.store', $invoice), $form)->assertSessionHasNoErrors();
        $this->post(route('payments.store', $invoice), $form)
            ->assertRedirect($first->headers->get('Location'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Déjà enregistré'));
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(40000, $invoice->fresh()->amount_paid);

        // Un autre formulaire (autre identifiant) : un vrai second paiement.
        $this->post(route('payments.store', $invoice), ['_once' => '11111111-2222-4333-8444-555555555555'] + $form)->assertSessionHasNoErrors();
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_form_with_errors_can_be_corrected_and_resent(): void
    {
        $invoice = $this->sentInvoice();
        $once = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $this->post(route('payments.store', $invoice), ['amount' => 'abc', 'paid_at' => '2026-10-01', 'method' => 'cheque', '_once' => $once])
            ->assertSessionHasErrors('amount');
        $this->post(route('payments.store', $invoice), ['amount' => '100', 'paid_at' => '2026-10-01', 'method' => 'cheque', '_once' => $once])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_invoice_pdf_is_updated_with_payment_date_and_method(): void
    {
        $invoice = $this->sentInvoice();
        $original = $invoice->snapshots()->sole();

        $this->post(route('payments.store', $invoice), ['amount' => '400', 'paid_at' => '2026-09-28', 'method' => 'cheque', 'reference' => '1234567'])->assertSessionHasNoErrors();
        $this->assertSame(2, $invoice->snapshots()->count(), 'Nouvelle version du PDF après le paiement.');

        // Le PDF (dernière version) liste le règlement ; celui d'origine reste disponible tel quel.
        $pdfs = app(PdfService::class);
        $html = view('pdf.document', (fn () => $this->viewData($invoice->fresh()))->call($pdfs))->render();
        $this->assertStringContainsString('Règlements reçus', $html);
        $this->assertStringContainsString('Le 28/09/2026', $html);
        $this->assertStringContainsString('Chèque — réf. 1234567', $html);
        $this->assertStringNotContainsString('FACTURE ACQUITTÉE', $html);
        $this->assertNotSame($original->sha256, $invoice->fresh()->snapshot->sha256);
        $this->assertSame(Storage::disk('local')->get($original->path), $this->get(route('invoices.pdf', ['invoice' => $invoice, 'version' => 'origine']))->getContent());
        $this->get(route('invoices.show', $invoice))->assertSee("Voir le PDF d'origine", false)->assertSee('Mettre à jour le PDF');

        // Soldée : « Facture acquittée ».
        $this->post(route('payments.store', $invoice), ['amount' => '600', 'paid_at' => '2026-10-01', 'method' => 'virement'])->assertSessionHasNoErrors();
        $html = view('pdf.document', (fn () => $this->viewData($invoice->fresh()))->call($pdfs))->render();
        $this->assertStringContainsString('FACTURE ACQUITTÉE', $html);
        $this->assertStringContainsString('le 01/10/2026', $html);

        // Bouton « Mettre à jour le PDF ».
        $this->post(route('invoices.pdf.refresh', $invoice))->assertSessionHas('status', fn ($s) => str_contains($s, 'PDF mis à jour'));
        $this->assertSame(4, $invoice->snapshots()->count());
    }
}
