<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Documents scannés ou ajoutés sur les devis et factures. */
class DocumentScanTest extends TestCase
{
    use RefreshDatabase;

    private Quote $quote;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs($this->admin());
        $this->post(route('quotes.store'), ['client_id' => Client::factory()->create(['last_name' => 'Martin'])->id, 'title' => 'Toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réfection', 'quantity' => '1', 'unit_price' => '900']]])->assertSessionHasNoErrors();
        $this->quote = Quote::query()->sole();
    }

    public function test_photos_become_one_pdf_on_the_quote_and_the_client(): void
    {
        $this->get(route('quotes.show', $this->quote))->assertSee('Scanner un document')->assertSee('data-scan-camera', false);

        $this->post(route('attachments.quote.store', $this->quote), [
            'mode' => 'scan', 'scan_name' => 'Bon de commande signé',
            'pages' => [UploadedFile::fake()->image('page1.jpg', 1200, 1600), UploadedFile::fake()->image('page2.jpg', 3000, 4000)],
        ])->assertRedirect(route('quotes.show', $this->quote).'#documents')->assertSessionHasNoErrors();

        $doc = Attachment::query()->sole();
        $this->assertSame(['Bon de commande signé.pdf', 'application/pdf', $this->quote->id, $this->quote->client_id], [$doc->name, $doc->mime, $doc->quote_id, $doc->client_id]);
        $pdf = Storage::disk('local')->get($doc->path);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(2, preg_match_all('#/Type\s*/Page[^s]#', $pdf));

        $this->get(route('quotes.show', $this->quote))->assertSee('Bon de commande signé.pdf');
        $this->get(route('clients.show', $this->quote->client_id))->assertSee('Bon de commande signé.pdf')->assertSee('devis');

        // Sans photo, ou avec autre chose qu'une photo : refusé.
        $this->post(route('attachments.quote.store', $this->quote), ['mode' => 'scan'])->assertSessionHasErrors('pages');
        $this->post(route('attachments.quote.store', $this->quote), ['mode' => 'scan', 'pages' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])
            ->assertSessionHasErrors('pages.0');
    }

    public function test_files_on_an_invoice_are_for_the_manager_only(): void
    {
        $this->post(route('quotes.send', $this->quote));
        $this->post(route('quotes.accept', $this->quote));
        $this->post(route('quotes.invoice', $this->quote), ['kind' => 'standard']);
        $invoice = Invoice::query()->sole();

        $this->post(route('attachments.invoice.store', $invoice), ['files' => [UploadedFile::fake()->create('ticket.pdf', 50, 'application/pdf')]])
            ->assertRedirect(route('invoices.show', $invoice).'#documents');
        $doc = Attachment::query()->sole();
        $this->assertSame([$invoice->id, null], [$doc->invoice_id, $doc->quote_id]);
        $this->get(route('invoices.show', $invoice))->assertSee('ticket.pdf');

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->post(route('attachments.quote.store', $this->quote), ['mode' => 'scan', 'pages' => [UploadedFile::fake()->image('p.jpg')]])->assertSessionHasNoErrors();
        $this->post(route('attachments.invoice.store', $invoice), ['files' => [UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')]])->assertForbidden();
        $this->get(route('attachments.show', $doc))->assertForbidden();
        $this->get(route('clients.show', $this->quote->client_id))->assertDontSee('ticket.pdf');
    }
}
