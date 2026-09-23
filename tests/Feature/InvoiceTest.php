<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Worksite;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Worksite $worksite;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-26 10:00');
        $this->client = Client::factory()->create(['last_name' => 'Cheron']);
        $this->worksite = Worksite::factory()->for($this->client)->create(['address' => '5 impasse des Quatre Vents', 'city' => 'Gif-sur-Yvette']);
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Devis accepté de 2 000 € (franchise) : 584 € + 177 m² × 8 €. */
    private function acceptedQuote(array $overrides = []): Quote
    {
        $this->post(route('quotes.store'), array_replace([
            'client_id' => $this->client->id,
            'worksite_id' => $this->worksite->id,
            'title' => 'Isolation et traitement de toiture',
            'validity_days' => 30,
            'lines' => [
                ['type' => 'item', 'title' => 'Travaux d\'isolation', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '584', 'vat_rate' => 1000],
                ['type' => 'item', 'title' => 'Traitement de la toiture', 'quantity' => '177', 'unit' => 'm²', 'unit_price' => '8', 'vat_rate' => 1000],
            ],
        ], $overrides))->assertSessionHasNoErrors();

        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));

        return $quote->fresh();
    }

    private function invoiceFromQuote(Quote $quote, string $kind, ?string $percent = null): Invoice
    {
        $this->post(route('quotes.invoice', $quote), array_filter(['kind' => $kind, 'percent' => $percent]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return Invoice::query()->latest('id')->firstOrFail();
    }

    private function send(Invoice $invoice): Invoice
    {
        $this->post(route('invoices.send', $invoice))->assertSessionHasNoErrors();

        return $invoice->fresh();
    }

    public function test_invoice_pages_require_login(): void
    {
        auth()->logout();
        $this->get(route('invoices.index'))->assertRedirect(route('login'));
        $this->get(route('invoices.create'))->assertRedirect(route('login'));
    }

    public function test_vat_regime_can_be_chosen_on_each_quote(): void
    {
        $this->post(route('quotes.store'), [
            'client_id' => $this->client->id,
            'validity_days' => 30,
            'vat_regime' => 'assujetti',
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '1000', 'vat_rate' => 1000]],
        ])->assertSessionHasNoErrors();

        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->assertSame('assujetti', $quote->vat_regime, 'Choix du devis, même si l\'entreprise est en franchise.');
        $this->assertSame(110000, $quote->total_ttc);

        $this->get(route('quotes.edit', $quote))->assertOk()->assertSee('Avec TVA (taux au choix sur chaque ligne)');
        $this->put(route('quotes.update', $quote), [
            'client_id' => $this->client->id,
            'validity_days' => 30,
            'vat_regime' => 'franchise',
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '1000', 'vat_rate' => 1000]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(100000, $quote->fresh()->total_ttc);
        $this->assertSame('franchise', $quote->fresh()->vat_regime);
    }

    public function test_quote_copy_keeps_its_own_vat_regime(): void
    {
        $quote = $this->acceptedQuote(['vat_regime' => 'assujetti']);
        $this->post(route('quotes.duplicate', $quote), ['client_id' => $this->client->id]);

        $this->assertSame('assujetti', Quote::query()->latest('id')->first()->vat_regime);
    }

    public function test_only_accepted_quote_can_be_invoiced(): void
    {
        $this->post(route('quotes.store'), [
            'client_id' => $this->client->id, 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'A', 'quantity' => '1', 'unit_price' => '10']],
        ]);
        $quote = Quote::query()->latest('id')->first();

        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard'])->assertForbidden();
        $this->get(route('quotes.show', $quote))->assertDontSee('Préparer la facture');

        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $this->get(route('quotes.show', $quote))->assertSee('Préparer la facture')->assertSee('value="40"', false);
    }

    public function test_deposit_invoice_is_a_percentage_of_the_quote(): void
    {
        $quote = $this->acceptedQuote();
        $invoice = $this->invoiceFromQuote($quote, 'deposit', '40');

        $this->assertSame('deposit', $invoice->kind);
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertSame($quote->id, $invoice->quote_id);
        $this->assertSame(80000, $invoice->total_ttc);
        $this->assertCount(1, $invoice->lines);
        $this->assertStringContainsString('Acompte : 40 % du devis DEV-2026-0001', $invoice->lines[0]->title);

        $invoice = $this->send($invoice);
        $this->assertSame('FAC-2026-0001', $invoice->number);
        $this->assertSame('2026-09-26', $invoice->issue_date->toDateString());
        $this->assertSame('2026-09-26', $invoice->due_date->toDateString(), 'Payable à réception par défaut.');

        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('Facture d&#039;acompte FAC-2026-0001', false)
            ->assertSee('Devis n° DEV-2026-0001')
            ->assertSee('Payable à réception')
            ->assertSee('TVA non applicable, art. 293 B du CGI');
    }

    public function test_deposit_is_split_by_vat_rate_when_subject_to_vat(): void
    {
        $quote = $this->acceptedQuote([
            'vat_regime' => 'assujetti',
            'lines' => [
                ['type' => 'item', 'title' => 'Couverture', 'quantity' => '1', 'unit_price' => '1000', 'vat_rate' => 1000],
                ['type' => 'item', 'title' => 'Isolation', 'quantity' => '1', 'unit_price' => '500', 'vat_rate' => 550],
            ],
        ]);

        $invoice = $this->invoiceFromQuote($quote, 'deposit', '40');

        $this->assertSame('assujetti', $invoice->vat_regime);
        $this->assertSame([40000, 20000], $invoice->lines->pluck('unit_price')->all());
        $this->assertSame([1000, 550], $invoice->lines->pluck('vat_rate')->all());
        $this->assertSame(60000, $invoice->total_ht);
        $this->assertSame(4000 + 1100, $invoice->total_vat);
    }

    public function test_percent_is_required_and_validated_for_deposits(): void
    {
        $quote = $this->acceptedQuote();

        $this->post(route('quotes.invoice', $quote), ['kind' => 'deposit'])->assertSessionHasErrors('percent');
        $this->post(route('quotes.invoice', $quote), ['kind' => 'deposit', 'percent' => '150'])->assertSessionHasErrors('percent');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_final_invoice_deducts_previous_deposits(): void
    {
        $quote = $this->acceptedQuote();
        $this->send($this->invoiceFromQuote($quote, 'deposit', '40'));
        // Un acompte resté en brouillon n'est pas déduit.
        $this->invoiceFromQuote($quote, 'deposit', '10');

        $final = $this->invoiceFromQuote($quote, 'final');

        $this->assertSame('final', $final->kind);
        $this->assertSame(120000, $final->total_ttc, '2 000 € − 800 € d\'acompte.');
        $this->assertSame('Déjà facturé', $final->lines->firstWhere('type', 'section')->title);
        $this->assertSame(-80000, $final->lines->last()->unit_price);
        $this->assertStringContainsString('FAC-2026-0001', $final->lines->last()->title);

        $final = $this->send($final);
        $this->assertSame('FAC-2026-0002', $final->number);
        $this->get(route('quotes.show', $quote))->assertSee('Facturé')->assertSee("2\u{202F}000,00\u{00A0}€ sur", false);
    }

    public function test_final_invoice_keeps_quote_discount_out_of_deductions(): void
    {
        $quote = $this->acceptedQuote(['discount_type' => 'percent', 'discount_value' => '10']);
        $this->assertSame(180000, $quote->total_ttc);
        $this->send($this->invoiceFromQuote($quote, 'deposit', '50'));

        $final = $this->invoiceFromQuote($quote, 'final');

        $this->assertNull($final->discount_type);
        $this->assertSame(90000, $final->total_ttc, '1 800 € remisés − 900 € d\'acompte.');
    }

    public function test_full_invoice_copies_quote_lines(): void
    {
        $quote = $this->acceptedQuote();
        $invoice = $this->invoiceFromQuote($quote, 'standard');

        $this->assertSame(['Travaux d\'isolation', 'Traitement de la toiture'], $invoice->lines->pluck('title')->all());
        $this->assertSame(200000, $invoice->total_ttc);
        $this->assertSame($this->worksite->id, $invoice->worksite_id);
    }

    public function test_invoice_with_optional_line_cannot_be_sent(): void
    {
        $quote = $this->acceptedQuote(['lines' => [
            ['type' => 'item', 'title' => 'Traitement', 'quantity' => '1', 'unit_price' => '1000'],
            ['type' => 'item', 'title' => 'Hydrofuge', 'quantity' => '1', 'unit_price' => '300', 'is_optional' => '1'],
        ]]);
        $invoice = $this->invoiceFromQuote($quote, 'standard');

        $this->post(route('invoices.send', $invoice))->assertSessionHasErrors('send');
        $this->assertNull($invoice->fresh()->number);
    }

    public function test_free_invoice_can_be_created_edited_and_sent(): void
    {
        $this->get(route('invoices.create', ['client' => $this->client->id]))->assertOk()->assertSee('Nouvelle facture');

        $this->post(route('invoices.store'), [
            'client_id' => $this->client->id,
            'worksite_id' => $this->worksite->id,
            'title' => 'Réparation fuite',
            'due_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Recherche de fuite', 'quantity' => '2', 'unit' => 'h', 'unit_price' => '55']],
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('standard', $invoice->kind);
        $this->assertSame(11000, $invoice->total_ttc);
        $this->assertSame('franchise', $invoice->vat_regime);

        $this->get(route('invoices.edit', $invoice))->assertOk();
        $invoice = $this->send($invoice);
        $this->assertSame('2026-10-26', $invoice->due_date->toDateString());
        $this->get(route('invoices.edit', $invoice))->assertRedirect(route('invoices.show', $invoice));
        $this->put(route('invoices.update', $invoice), ['client_id' => $this->client->id, 'due_days' => 0])->assertForbidden();
    }

    public function test_modifying_a_sent_invoice_creates_credit_note_and_corrected_draft(): void
    {
        $quote = $this->acceptedQuote();
        $invoice = $this->send($this->invoiceFromQuote($quote, 'deposit', '40'));

        $this->post(route('invoices.correct', $invoice))->assertRedirect();

        $invoice->refresh();
        $credit = Invoice::query()->credits()->firstOrFail();
        $draft = Invoice::query()->where('corrects_id', $invoice->id)->firstOrFail();

        $this->assertSame('cancelled', $invoice->status);
        $this->assertSame('AV-2026-0001', $credit->number);
        $this->assertSame($invoice->id, $credit->cancels_id);
        $this->assertSame(80000, $credit->total_ttc);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('deposit', $draft->kind);
        $this->assertSame(80000, $draft->total_ttc);

        $draft = $this->send($draft);
        $this->assertSame('FAC-2026-0002', $draft->number, 'Nouveau numéro, la suite reste continue.');

        $this->get(route('invoices.show', $invoice))->assertSee('Facture annulée')->assertSee('AV-2026-0001')->assertSee('FAC-2026-0002');
        $this->get(route('invoices.show', $credit))->assertSee('Annule la facture n° FAC-2026-0001');
        $this->post(route('invoices.correct', $invoice))->assertForbidden();
    }

    public function test_sent_invoice_can_be_cancelled_by_credit_note(): void
    {
        $quote = $this->acceptedQuote();
        $invoice = $this->send($this->invoiceFromQuote($quote, 'standard'));

        $this->post(route('invoices.cancel', $invoice), ['reason' => 'Travaux annulés'])->assertRedirect();

        $credit = Invoice::query()->credits()->firstOrFail();
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('Motif : Travaux annulés', $credit->notes);
        $this->assertSame(0, Invoice::query()->where('status', 'draft')->count());
    }

    public function test_sent_invoice_cannot_be_deleted_but_draft_can(): void
    {
        $quote = $this->acceptedQuote();
        $sent = $this->send($this->invoiceFromQuote($quote, 'deposit', '40'));
        $draft = $this->invoiceFromQuote($quote, 'final');

        $this->delete(route('invoices.destroy', $sent))->assertForbidden();
        $this->delete(route('invoices.destroy', $draft))->assertRedirect(route('invoices.index'));
        $this->assertSoftDeleted($draft);

        $this->get(route('trash.index'))->assertSee('Brouillons de factures');
        $this->post(route('trash.invoices.restore', $draft->id))->assertRedirect(route('invoices.show', $draft));
        $this->assertNotSoftDeleted($draft);
    }

    public function test_client_with_invoices_cannot_be_trashed(): void
    {
        $this->post(route('invoices.store'), [
            'client_id' => $this->client->id, 'due_days' => 0,
            'lines' => [['type' => 'item', 'title' => 'A', 'quantity' => '1', 'unit_price' => '10']],
        ]);

        $this->delete(route('clients.destroy', $this->client))->assertSessionHasErrors('client');
        $this->assertNotSoftDeleted($this->client);
    }

    public function test_dashboard_shows_amount_to_collect_and_revenue(): void
    {
        $quote = $this->acceptedQuote();
        $deposit = $this->send($this->invoiceFromQuote($quote, 'deposit', '40'));
        $this->send($this->invoiceFromQuote($quote, 'deposit', '10'));
        $this->post(route('invoices.cancel', $deposit));

        $this->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Montant à encaisser', "200,00\u{00A0}€"], false)
            ->assertSeeInOrder(['CA facturé du mois (HT)', "200,00\u{00A0}€"], false);
    }

    public function test_invoice_list_filters_and_search(): void
    {
        $quote = $this->acceptedQuote();
        $this->send($this->invoiceFromQuote($quote, 'deposit', '40'));
        $this->invoiceFromQuote($quote, 'final');

        $this->get(route('invoices.index'))->assertOk()->assertSee('FAC-2026-0001')->assertSee('Brouillon');
        $this->get(route('invoices.index', ['status' => 'unpaid']))->assertSee('FAC-2026-0001')->assertDontSee('Facture de solde');
        $this->get(route('invoices.index', ['q' => 'DEV-2026-0001']))->assertSee('FAC-2026-0001');
        $this->get(route('search', ['q' => 'FAC-2026-0001']))->assertSee('Factures')->assertSee('Facture d&#039;acompte', false);
    }

    public function test_overdue_invoice_is_flagged(): void
    {
        $this->post(route('invoices.store'), [
            'client_id' => $this->client->id, 'due_days' => 15,
            'lines' => [['type' => 'item', 'title' => 'A', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        $invoice = $this->send(Invoice::query()->firstOrFail());

        Carbon::setTestNow('2026-10-20 10:00');
        $this->assertTrue($invoice->fresh()->isOverdue());
        $this->get(route('invoices.index', ['status' => 'overdue']))->assertSee('En retard');
        $this->get(route('dashboard'))->assertSee('1 facture(s) en retard');
    }

    public function test_invoice_defaults_can_be_configured(): void
    {
        app(Settings::class)->set(['documents.invoice_due_days' => 30, 'documents.deposit_percent' => 30]);
        $quote = $this->acceptedQuote();

        $this->get(route('invoices.create'))->assertSee('<option value="30" selected>', false);
        $this->get(route('quotes.show', $quote))->assertSee('value="30"', false);
    }
}
