<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\Quote;
use App\Models\Worksite;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Worksite $worksite;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00');
        $this->client = Client::factory()->create(['last_name' => 'Cheron', 'status' => 'prospect']);
        $this->worksite = Worksite::factory()->for($this->client)->create(['address' => '5 impasse des Quatre Vents', 'city' => 'Gif-sur-Yvette']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'client_id' => $this->client->id,
            'worksite_id' => $this->worksite->id,
            'title' => 'Isolation et traitement de toiture',
            'validity_days' => 30,
            'payment_terms' => 'Acompte de 40 % à la signature du devis, puis 60 % à la fin des travaux.',
            'notes' => 'Ces travaux sont effectués pour un prix global et forfaitaire.',
            'lines' => [
                ['type' => 'section', 'title' => 'Isolation'],
                ['type' => 'item', 'title' => 'Travaux d\'isolation', 'description' => "Dépose de la première couche\nPose de l'isolant", 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '584', 'vat_rate' => 1000],
                ['type' => 'section', 'title' => 'Toiture'],
                ['type' => 'item', 'title' => 'Traitement de la toiture (Dalep 2100)', 'quantity' => '177', 'unit' => 'm²', 'unit_price' => '8,00', 'vat_rate' => 1000],
                ['type' => 'item', 'title' => 'Ajout d\'un hydrofuge (OFFERT)', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'is_offered' => '1'],
                ['type' => 'text', 'description' => 'Tuiles fournies par le client.'],
            ],
        ], $overrides);
    }

    private function createQuote(array $overrides = []): Quote
    {
        $this->actingAs($this->admin())->post(route('quotes.store'), $this->payload($overrides))->assertSessionHasNoErrors();

        return Quote::query()->latest('id')->firstOrFail();
    }

    public function test_quote_pages_require_login(): void
    {
        $this->get(route('quotes.index'))->assertRedirect(route('login'));
        $this->get(route('quotes.create'))->assertRedirect(route('login'));
    }

    public function test_draft_is_saved_with_lines_totals_and_no_number(): void
    {
        $quote = $this->createQuote();

        $this->assertSame('draft', $quote->status);
        $this->assertNull($quote->number);
        $this->assertSame('franchise', $quote->vat_regime, 'Régime actuel de l\'entreprise (franchise en base).');
        $this->assertSame(200000, $quote->total_ht);
        $this->assertSame(0, $quote->total_vat);
        $this->assertSame(200000, $quote->total_ttc);
        $this->assertSame(['section', 'item', 'section', 'item', 'item', 'text'], $quote->lines->pluck('type')->all());
        $this->assertSame(177000, $quote->lines[3]->quantity);
        $this->assertSame(141600, $quote->lines[3]->total_ht);
        $this->assertDatabaseHas('activity_log', ['action' => 'quote.created']);
    }

    public function test_quote_page_shows_lines_steps_sections_and_franchise_mention(): void
    {
        $quote = $this->createQuote();

        $this->get(route('quotes.show', $quote))
            ->assertOk()
            ->assertSee('Travaux d\'isolation')
            ->assertSee('Pose de l\'isolant')
            ->assertSeeInOrder(['Isolation', 'Travaux d&#039;isolation', 'Toiture', 'Traitement de la toiture'], false)
            ->assertSee('Offert')
            ->assertSee('TVA non applicable, art. 293 B du CGI')
            ->assertSee("2\u{202F}000,00\u{00A0}€", false);
    }

    public function test_vat_is_applied_when_company_is_subject_to_vat(): void
    {
        app(Settings::class)->set(['vat.regime' => 'assujetti']);
        $quote = $this->createQuote();

        $this->assertSame('assujetti', $quote->vat_regime);
        $this->assertSame(20000, $quote->total_vat);
        $this->assertSame(220000, $quote->total_ttc);
        $this->get(route('quotes.show', $quote))->assertSee('Le client atteste', false);
    }

    public function test_line_order_follows_the_submitted_order(): void
    {
        $quote = $this->createQuote(['lines' => [
            ['type' => 'item', 'title' => 'B', 'quantity' => '1', 'unit_price' => '1'],
            ['type' => 'item', 'title' => 'A', 'quantity' => '1', 'unit_price' => '1'],
        ]]);

        $this->assertSame(['B', 'A'], $quote->lines->pluck('title')->all());
        $this->assertSame([1, 2], $quote->lines->pluck('position')->all());
    }

    public function test_invalid_lines_are_rejected_with_clear_messages(): void
    {
        $this->actingAs($this->admin())->post(route('quotes.store'), $this->payload(['lines' => [
            ['type' => 'item', 'title' => '', 'quantity' => 'abc', 'unit_price' => '8,5,0'],
        ]]))->assertSessionHasErrors(['lines.0.title', 'lines.0.quantity', 'lines.0.unit_price']);

        $this->assertSame(0, Quote::query()->count());
    }

    public function test_worksite_must_belong_to_the_client(): void
    {
        $other = Worksite::factory()->create();

        $this->actingAs($this->admin())->post(route('quotes.store'), $this->payload(['worksite_id' => $other->id]))
            ->assertSessionHasErrors('worksite_id');
    }

    public function test_global_discount_is_saved(): void
    {
        $quote = $this->createQuote(['discount_type' => 'amount', 'discount_value' => '100']);

        $this->assertSame(190000, $quote->total_ttc);
    }

    public function test_draft_can_be_edited_and_lines_are_replaced(): void
    {
        $quote = $this->createQuote();

        $this->put(route('quotes.update', $quote), $this->payload(['lines' => [
            ['type' => 'item', 'title' => 'Remplacement d\'une faîtière', 'quantity' => '2', 'unit' => 'u', 'unit_price' => '150'],
        ]]))->assertRedirect(route('quotes.show', $quote));

        $quote->refresh();
        $this->assertCount(1, $quote->lines);
        $this->assertSame(30000, $quote->total_ttc);
    }

    public function test_sending_assigns_the_next_number_and_dates(): void
    {
        $quote = $this->createQuote();

        $this->post(route('quotes.send', $quote))->assertRedirect(route('quotes.show', $quote));

        $quote->refresh();
        $this->assertSame('DEV-2026-0001', $quote->number);
        $this->assertSame('sent', $quote->status);
        $this->assertSame('2026-09-25', $quote->issue_date->toDateString());
        $this->assertSame('2026-10-25', $quote->valid_until->toDateString());

        $second = $this->createQuote();
        $this->post(route('quotes.send', $second));
        $this->assertSame('DEV-2026-0002', $second->fresh()->number);
    }

    public function test_quote_without_item_cannot_be_sent(): void
    {
        $quote = $this->createQuote(['lines' => [['type' => 'text', 'description' => 'Visite']]]);

        $this->post(route('quotes.send', $quote))->assertSessionHasErrors('send');
        $this->assertNull($quote->fresh()->number);
    }

    public function test_sent_quote_cannot_be_modified_but_can_be_trashed(): void
    {
        $quote = $this->createQuote();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));

        $this->get(route('quotes.edit', $quote))->assertRedirect(route('quotes.show', $quote));
        $this->put(route('quotes.update', $quote), $this->payload())->assertForbidden();

        $this->get(route('quotes.show', $quote))->assertSee('Supprimer le devis');
        $this->delete(route('quotes.destroy', $quote))->assertRedirect(route('quotes.index'));
        $this->assertSoftDeleted($quote);
        $this->get(route('trash.index'))->assertSee('DEV-2026-0001');
        $this->post(route('trash.quotes.restore', $quote->id))->assertRedirect(route('quotes.show', $quote));
        $this->assertNotSoftDeleted($quote);
    }

    public function test_invoiced_quote_cannot_be_trashed(): void
    {
        $quote = $this->createQuote();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard']);

        $this->delete(route('quotes.destroy', $quote))->assertSessionHasErrors('send');
        $this->assertNotSoftDeleted($quote);
    }

    public function test_new_version_gets_a_new_number_and_replaces_the_old_one(): void
    {
        $quote = $this->createQuote();
        $this->post(route('quotes.send', $quote));

        $this->post(route('quotes.revise', $quote));
        $draft = Quote::query()->where('replaces_id', $quote->id)->firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->assertCount(6, $draft->lines, 'Les lignes sont recopiées.');

        // Demander une nouvelle version une 2e fois rouvre le même brouillon.
        $this->post(route('quotes.revise', $quote))->assertRedirect(route('quotes.edit', $draft));

        $this->post(route('quotes.send', $draft));
        $this->assertSame('DEV-2026-0002', $draft->fresh()->number);
        $this->assertSame('replaced', $quote->fresh()->status);
        $this->assertSame($draft->id, $quote->fresh()->replaced_by_id);
    }

    public function test_accepting_turns_the_prospect_into_a_client(): void
    {
        $quote = $this->createQuote();
        $this->post(route('quotes.send', $quote));

        $this->post(route('quotes.accept', $quote))->assertRedirect(route('quotes.show', $quote));

        $this->assertSame('accepted', $quote->fresh()->status);
        $this->assertSame('client', $this->client->fresh()->status);
    }

    public function test_draft_cannot_be_accepted(): void
    {
        $quote = $this->createQuote();

        $this->post(route('quotes.accept', $quote))->assertForbidden();
    }

    public function test_refusal_keeps_the_reason(): void
    {
        $quote = $this->createQuote();
        $this->post(route('quotes.send', $quote));

        $this->post(route('quotes.refuse', $quote), ['refusal_reason' => 'A choisi un autre artisan']);

        $this->assertSame('refused', $quote->fresh()->status);
        $this->get(route('quotes.show', $quote))->assertSee('A choisi un autre artisan');
    }

    public function test_quotes_expire_after_their_validity(): void
    {
        $quote = $this->createQuote(['validity_days' => 10]);
        $this->post(route('quotes.send', $quote));

        Carbon::setTestNow('2026-10-05');
        $this->artisan('app:expire-quotes')->assertSuccessful();
        $this->assertSame('sent', $quote->fresh()->status, 'Encore valable le dernier jour.');

        Carbon::setTestNow('2026-10-06');
        $this->artisan('app:expire-quotes')->assertSuccessful();
        $this->assertSame('expired', $quote->fresh()->status);

        // Un devis expiré peut encore être accepté si le client revient.
        $this->post(route('quotes.accept', $quote))->assertRedirect();
        $this->assertSame('accepted', $quote->fresh()->status);
    }

    public function test_duplicate_to_another_client(): void
    {
        $quote = $this->createQuote();
        $other = Client::factory()->create();
        $otherSite = Worksite::factory()->for($other)->create();

        $this->post(route('quotes.duplicate', $quote), ['client_id' => $other->id]);

        $copy = Quote::query()->where('client_id', $other->id)->firstOrFail();
        $this->assertSame('draft', $copy->status);
        $this->assertSame($otherSite->id, $copy->worksite_id);
        $this->assertSame($quote->total_ttc, $copy->total_ttc);
        $this->assertCount(6, $copy->lines);
    }

    public function test_draft_goes_to_trash_and_can_be_restored(): void
    {
        $quote = $this->createQuote();

        $this->delete(route('quotes.destroy', $quote))->assertRedirect(route('quotes.index'));
        $this->assertSoftDeleted($quote);

        $this->post(route('trash.quotes.restore', $quote->id));
        $this->assertNotSoftDeleted($quote);
    }

    public function test_client_with_quotes_cannot_be_trashed(): void
    {
        $this->createQuote();

        $this->delete(route('clients.destroy', $this->client))->assertSessionHasErrors('client');
        $this->assertNotSoftDeleted($this->client);
    }

    public function test_quote_list_filters_and_search_by_number(): void
    {
        $sent = $this->createQuote(['title' => 'Devis envoyé']);
        $this->post(route('quotes.send', $sent));
        $this->createQuote(['title' => 'Devis brouillon']);

        $this->get(route('quotes.index', ['status' => 'sent']))->assertSee('Devis envoyé')->assertDontSee('Devis brouillon');
        $this->get(route('quotes.index', ['status' => 'draft']))->assertSee('Devis brouillon')->assertDontSee('Devis envoyé');
        $this->get(route('quotes.index', ['q' => 'DEV-2026-0001']))->assertSee('Devis envoyé');
        $this->get(route('search', ['q' => '0001']))->assertSee('DEV-2026-0001');
        $this->get(route('search', ['q' => 'cheron']))->assertSee('DEV-2026-0001');
    }

    public function test_dashboard_counts_pending_quotes(): void
    {
        $quote = $this->createQuote();
        $this->post(route('quotes.send', $quote));

        $this->get(route('dashboard'))->assertSee("2\u{202F}000,00\u{00A0}€ au total", false);
    }

    public function test_editor_opens_prefilled_for_a_client_with_catalog_and_templates(): void
    {
        $this->actingAs($this->admin())->get(route('quotes.create', ['client' => $this->client->id]))
            ->assertOk()
            ->assertSee('Traitement de la toiture (Dalep 2100)', false)
            ->assertSee('Acompte de 40 %', false)
            ->assertSee('Ces travaux sont effectués pour un prix global et forfaitaire.', false)
            ->assertSee('Mise en place d&#039;une échelle à coulisse', false);
    }

    public function test_client_page_lists_its_quotes(): void
    {
        $quote = $this->createQuote(['title' => 'Traitement toiture Cheron']);

        $this->get(route('clients.show', $this->client))->assertSee('Traitement toiture Cheron')->assertSee(route('quotes.show', $quote), false);
    }

    public function test_catalog_seeded_from_real_quotes(): void
    {
        $item = CatalogItem::query()->where('name', 'Traitement de la toiture (Dalep 2100)')->firstOrFail();

        $this->assertSame('m²', $item->unit);
        $this->assertSame(800, $item->unit_price);
        $this->assertStringContainsString('Dalep 2100', $item->description);
        $this->assertGreaterThan(30, CatalogItem::query()->count());
    }
}
