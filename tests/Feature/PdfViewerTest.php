<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfViewerTest extends TestCase
{
    use RefreshDatabase;

    private Quote $quote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => 'Démoussage', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '500']]]);
        $this->quote = Quote::query()->sole();
    }

    public function test_pdf_opens_in_the_viewer_with_a_close_button(): void
    {
        $page = $this->get(route('quotes.show', $this->quote))->assertOk();
        $this->assertStringContainsString('/apercu-pdf?', $page->getContent());

        $src = route('quotes.pdf', $this->quote, absolute: false);
        $this->get(route('pdf.view', ['src' => $src, 'titre' => 'Devis brouillon', 'retour' => '/devis/'.$this->quote->id]))
            ->assertOk()
            ->assertSee('Fermer')->assertSee('Partager')->assertSee('Devis brouillon')
            ->assertSee('data-src="'.$src.'"', false)
            ->assertSee('href="/devis/'.$this->quote->id.'"', false)
            ->assertSee('js/pdf-viewer.js', false);
    }

    public function test_viewer_refuses_other_sites_and_unknown_pages(): void
    {
        foreach (['https://exemple.com/x.pdf', '//exemple.com/x.pdf', 'javascript:alert(1)', '/page-inconnue', ''] as $src) {
            $this->get(route('pdf.view', ['src' => $src]))->assertNotFound();
        }

        // Adresse de retour vers un autre site : remplacée par l'accueil.
        $this->get(route('pdf.view', ['src' => route('quotes.pdf', $this->quote, absolute: false), 'retour' => '//exemple.com']))
            ->assertOk()->assertDontSee('exemple.com')->assertSee('href="/"', false);
    }

    public function test_commercial_cannot_open_an_invoice_through_the_viewer(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('pdf.view', ['src' => '/factures/1/pdf']))->assertForbidden();
        $this->get(route('pdf.view', ['src' => route('quotes.pdf', $this->quote, absolute: false)]))->assertOk();

        auth()->logout();
        $this->get(route('pdf.view', ['src' => route('quotes.pdf', $this->quote, absolute: false)]))->assertRedirect(route('login'));
    }
}
