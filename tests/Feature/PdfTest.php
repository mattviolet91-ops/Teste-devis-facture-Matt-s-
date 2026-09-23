<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Snapshot;
use App\Models\Worksite;
use App\Services\PdfService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-27 10:00');
        $this->client = Client::factory()->create(['last_name' => 'Dupont', 'first_name' => 'Hélène', 'civility' => 'Mme']);
        Worksite::factory()->for($this->client)->create(['address' => '12 rue des Tilleuls', 'city' => 'Massy']);
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function quote(array $overrides = []): Quote
    {
        $this->post(route('quotes.store'), array_replace([
            'client_id' => $this->client->id,
            'worksite_id' => $this->client->worksites()->value('id'),
            'title' => 'Traitement de toiture',
            'validity_days' => 30,
            'waste_estimate' => 'environ 1 m³ de débris',
            'lines' => [
                ['type' => 'item', 'title' => 'Démoussage de la toiture', 'description' => "Brossage\nRinçage", 'quantity' => '120', 'unit' => 'm²', 'unit_price' => '12'],
            ],
        ], $overrides))->assertSessionHasNoErrors();

        return Quote::query()->latest('id')->firstOrFail();
    }

    public function test_draft_quote_pdf_is_generated_on_the_fly(): void
    {
        $quote = $this->quote();

        $response = $this->get(route('quotes.pdf', $quote));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('inline; filename="Devis brouillon - Mme Helene Dupont.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertSame(0, Snapshot::query()->count(), 'Un brouillon n\'est jamais figé.');
    }

    public function test_sent_quote_is_frozen_with_its_fingerprint(): void
    {
        $quote = $this->quote();
        $this->post(route('quotes.send', $quote));

        $snapshot = Snapshot::query()->sole();
        $this->assertSame($quote->id, $snapshot->document_id);
        Storage::disk('local')->assertExists($snapshot->path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($snapshot->path)), $snapshot->sha256);

        // Un changement de réglages n'altère pas le document déjà envoyé.
        app(Settings::class)->set(['company.phone' => '01 02 03 04 05']);
        $response = $this->get(route('quotes.pdf', ['quote' => $quote, 'telecharger' => 1]));
        $this->assertSame($snapshot->sha256, hash('sha256', $response->getContent()));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame(1, Snapshot::query()->count());
    }

    public function test_missing_snapshot_is_rebuilt_for_documents_sent_before_phase_8(): void
    {
        $quote = $this->quote();
        $this->post(route('quotes.send', $quote));
        Snapshot::query()->delete();

        $this->get(route('quotes.pdf', $quote))->assertOk();
        $this->assertSame(1, Snapshot::query()->count());
    }

    public function test_quote_pdf_contains_mandatory_mentions_and_annexes(): void
    {
        app(Settings::class)->set(['company.mediator_name' => 'Médiateur Test']);
        $quote = $this->quote();

        $html = view('pdf.document', $this->viewData($quote))->render();

        $this->assertStringContainsString('Matt&#039;s Couverture</b> (EI)', $html);
        $this->assertStringNotContainsString('Violet', $html);
        $this->assertStringContainsString('SIRET 98170816700011', $html);
        $this->assertStringContainsString('TVA non applicable, art. 293 B du CGI', $html);
        $this->assertStringContainsString('QBE Europe SA/NV', $html);
        $this->assertStringContainsString('037 0010701-D1002575', $html);
        $this->assertStringContainsString('Gestion des déchets', $html);
        $this->assertStringContainsString('environ 1 m³ de débris', $html);
        $this->assertStringContainsString('Bon pour accord', $html);
        $this->assertStringContainsString('Médiateur Test', $html);
        $this->assertStringContainsString('Conditions générales de vente', $html);
        $this->assertStringNotContainsString('Formulaire de rétractation', $html);
    }

    public function test_professional_client_siret_is_printed(): void
    {
        $this->client->update(['type' => 'entreprise', 'company_name' => 'SCI Les Tilleuls', 'siret' => '12345678900012']);
        $quote = $this->quote();

        $html = view('pdf.document', $this->viewData($quote))->render();

        $this->assertStringContainsString('SIRET 12345678900012', $html);
    }

    public function test_invoice_pdf_has_invoice_mentions(): void
    {
        $quote = $this->quote(['show_bank' => '1']);
        app(Settings::class)->set(['bank.iban' => 'FR7630001007941234567890185', 'bank.holder' => 'Titulaire Test']);
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard']);
        $invoice = Invoice::query()->firstOrFail();
        $invoice->forceFill(['work_period' => 'du 12 au 14 octobre 2026'])->save();

        $html = view('pdf.document', $this->viewData($invoice))->render();

        $this->assertStringContainsString('Devis n°', $html);
        $this->assertStringContainsString('DEV-2026-0001', $html);
        $this->assertStringContainsString('du 12 au 14 octobre 2026', $html);
        $this->assertStringContainsString('prestation de services', $html);
        $this->assertStringContainsString('FR76 3000 1007 9412 3456 7890 185', $html);
        $this->assertStringNotContainsString('Formulaire de rétractation', $html);

        $this->post(route('invoices.send', $invoice));
        $this->get(route('invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(2, Snapshot::query()->count(), 'Devis et facture figés.');
    }

    public function test_credit_note_is_frozen_when_issued(): void
    {
        $quote = $this->quote();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard']);
        $invoice = Invoice::query()->firstOrFail();
        $this->post(route('invoices.send', $invoice));
        $this->post(route('invoices.cancel', $invoice));

        $credit = Invoice::query()->credits()->sole();
        $this->assertNotNull($credit->snapshot);
        $this->get(route('invoices.pdf', $credit))->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="Avoir AV-2026-0001 - Mme Helene Dupont.pdf"');
    }

    public function test_pdf_requires_login(): void
    {
        $quote = $this->quote();
        auth()->logout();

        $this->get(route('quotes.pdf', $quote))->assertRedirect(route('login'));
    }

    public function test_documents_settings_can_be_updated(): void
    {
        $this->get(route('settings.documents'))->assertOk()->assertSee('Page de couverture')->assertSee('médiateur');

        $this->put(route('settings.documents'), [
            'pdf' => ['waste_mention' => 'Déchets évacués.', 'waste_facility' => 'Déchetterie de Villejust', 'cgv' => 'CGV', 'cover_invoices' => '1'],
        ])->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertSame('Déchetterie de Villejust', $settings->get('pdf.waste_facility'));
        $this->assertFalse($settings->get('pdf.cgv_enabled'));
        $this->assertFalse($settings->get('pdf.cover_quotes'));
        $this->assertTrue($settings->get('pdf.cover_invoices'));

        $this->put(route('settings.documents'), ['pdf' => ['waste_mention' => '']])->assertSessionHasErrors('pdf.waste_mention');
    }

    /** Données de la vue PDF, comme les prépare PdfService. */
    private function viewData(Quote|Invoice $document): array
    {
        $service = app(PdfService::class);
        $method = new \ReflectionMethod($service, 'viewData');

        return $method->invoke($service, $document);
    }
}
