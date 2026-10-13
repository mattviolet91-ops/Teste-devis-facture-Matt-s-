<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Services\PdfService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Raison sociale exigée par myPOS : affichée sur les pages publiques seulement si elle est renseignée. */
class LegalNameTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['portal.home', 'portal.cgv', 'portal.refunds', 'portal.legal', 'portal.payment'];

    public function test_nothing_is_shown_while_the_field_is_empty(): void
    {
        foreach (self::PAGES as $page) {
            $this->get(route($page))->assertOk()->assertDontSee('raison sociale')->assertDontSee('Directeur de la publication');
        }
    }

    public function test_legal_name_appears_on_public_pages_but_never_on_documents(): void
    {
        $this->actingAs($this->admin());
        $company = app(Settings::class)->group('company');
        $bank = app(Settings::class)->group('bank');
        $this->put(route('settings.company'), [
            'company' => array_merge($company, ['legal_name' => 'Exemple NOM EI']),
            'bank' => ['holder' => $bank['holder'] ?? '', 'iban' => '', 'bic' => '', 'card_link' => ''],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('settings.company'))->assertSee('value="Exemple NOM EI"', false);

        foreach (self::PAGES as $page) {
            $this->get(route($page))->assertOk()->assertSee('Exemple NOM EI');
        }
        $this->get(route('portal.legal'))->assertSee('Directeur de la publication')->assertSee('exerçant sous le nom commercial', false);
        $this->get(route('portal.payment'))->assertSee('Bénéficiaire des paiements');

        $this->post(route('quotes.store'), [
            'client_id' => Client::factory()->create()->id, 'title' => 'Toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '500']],
        ])->assertSessionHasNoErrors();
        $viewData = new \ReflectionMethod(PdfService::class, 'viewData');
        $html = view('pdf.document', $viewData->invoke(app(PdfService::class), Quote::query()->sole()))->render();
        $this->assertStringNotContainsString('Exemple NOM EI', $html);
    }
}
