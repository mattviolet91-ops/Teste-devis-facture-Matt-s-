<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\JobCostService;
use App\Services\QuickQuoteService;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Corrections trouvées en vérifiant toute l'application (octobre 2026). */
class CorrectionsAuditTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 10:00');
        $this->client = Client::factory()->create(['last_name' => 'Martin', 'city' => 'Massy', 'email' => 'martin@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function quote(array $lines, array $extra = []): Quote
    {
        $this->post(route('quotes.store'), array_replace([
            'client_id' => $this->client->id, 'title' => 'Toiture', 'validity_days' => 30, 'lines' => $lines,
        ], $extra))->assertSessionHasNoErrors();

        return Quote::query()->latest('id')->firstOrFail();
    }

    private function accepted(array $lines, array $extra = []): Quote
    {
        $quote = $this->quote($lines, $extra);
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));

        return $quote->fresh();
    }

    public function test_express_forfait_with_a_count_is_one_flat_price(): void
    {
        $this->actingAs($this->admin());
        $parse = fn (string $text) => array_map(fn ($l) => [$l['title'], $l['quantity'], $l['unit'], $l['unit_price_cents']], app(QuickQuoteService::class)->parse($text)['lines']);

        // « 2 velux forfait 1 800 € » : 1 800 € pour les deux, jamais 2 × 1 800 €.
        $this->assertSame([['Pose de 2 velux', '1', 'forfait', 180000]], $parse('Martin, pose de 2 velux forfait 1 800 €'));
        // « 25 € pièce » : l'unité du prix, pas un mot de la désignation.
        $this->assertSame([['Remplacement de tuiles', '3', 'u', 2500]], $parse('Martin, remplacement de 3 tuiles à 25 € pièce'));
        // Sans « forfait », le nombre reste la quantité.
        $this->assertSame([['Pose de velux', '2', 'u', 150000]], $parse('Martin, pose de 2 velux à 1 500 €'));
    }

    public function test_express_partial_library_match_is_flagged(): void
    {
        $this->actingAs($this->admin());
        CatalogItem::query()->create(['name' => 'Remplacement d\'un raccord Velux', 'unit' => 'u', 'unit_price' => 9000, 'vat_rate' => 1000, 'is_active' => true]);

        $line = app(QuickQuoteService::class)->parse('Martin, remplacement velux forfait 900 €')['lines'][0];

        $this->assertStringContainsString('reconnue comme « Remplacement d\'un raccord Velux »', implode(' ', $line['warnings']));
    }

    public function test_quantities_are_shown_with_a_comma_in_the_editor(): void
    {
        $this->assertSame('2,5', Quantity::input(2500));
        $this->assertSame('177', Quantity::input(177000));
        $this->assertSame(2500, Quantity::parse('2,5'));

        $this->actingAs($this->admin());
        $quote = $this->quote([['type' => 'item', 'title' => 'Démoussage', 'quantity' => '12,5', 'unit' => 'm²', 'unit_price' => '10']]);
        $this->get(route('quotes.edit', $quote))->assertSee('value="12,5"', false);
    }

    public function test_new_version_cannot_replace_a_quote_accepted_in_the_meantime(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcd efgh ijkl mnop'])->assertSessionHasNoErrors();
        $original = $this->quote([['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '1500']]);
        $this->post(route('quotes.send', $original));
        $this->post(route('quotes.revise', $original));
        $draft = Quote::query()->latest('id')->firstOrFail();
        // Le client signe l'ancienne version pendant que la nouvelle est en préparation.
        $this->post(route('quotes.accept', $original));

        $this->get(route('quotes.show', $draft))->assertSee('a accepté le devis');
        $this->post(route('quotes.send', $draft))->assertSessionHasErrors('send');
        $this->post(route('emails.store', ['devis' => $draft->id]), ['to' => 'martin@example.com', 'subject' => 'Devis', 'body' => 'Bonjour'])
            ->assertSessionHasErrors('to');
        $this->post(route('quotes.on-site.sign', $draft), ['name' => 'M. Martin', 'signature' => 'data:image/png;base64,AAAA', 'agree' => '1'])
            ->assertSessionHasErrors('signature');

        $this->assertSame(['accepted', 'draft'], [$original->fresh()->status, $draft->fresh()->status]);
        Mail::assertNothingSent();
    }

    public function test_draft_invoice_sent_by_email_gets_the_same_checks(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcd efgh ijkl mnop'])->assertSessionHasNoErrors();
        $quote = $this->accepted([
            ['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '1500'],
            ['type' => 'item', 'title' => 'Hydrofuge', 'quantity' => '1', 'unit_price' => '500', 'is_optional' => '1'],
        ]);
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard'])->assertSessionHas('status', fn ($s) => str_contains($s, 'options'));
        $invoice = Invoice::query()->latest('id')->firstOrFail();

        $this->post(route('emails.store', ['facture' => $invoice->id]), ['to' => 'martin@example.com', 'subject' => 'Facture', 'body' => 'Bonjour'])
            ->assertSessionHasErrors(['to' => 'Une facture ne peut pas contenir d\'option : décochez « Option » sur les lignes choisies par le client ou supprimez-les.']);

        $this->assertSame(['draft', null], [$invoice->fresh()->status, $invoice->fresh()->number]);
        Mail::assertNothingSent();
    }

    public function test_global_discount_gets_its_own_section_on_the_final_invoice(): void
    {
        $this->actingAs($this->admin());
        $quote = $this->accepted([
            ['type' => 'section', 'title' => 'Couverture'],
            ['type' => 'item', 'title' => 'Tuiles', 'quantity' => '1', 'unit_price' => '250'],
            ['type' => 'section', 'title' => 'Zinguerie'],
            ['type' => 'item', 'title' => 'Gouttière', 'quantity' => '10', 'unit_price' => '45,50'],
        ], ['discount_type' => 'percent', 'discount_value' => '10']);

        $this->post(route('quotes.invoice', $quote), ['kind' => 'final'])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->latest('id')->with('lines')->firstOrFail();

        $this->assertSame(['section', 'item', 'section', 'item', 'section', 'item'], $invoice->lines->pluck('type')->all());
        $this->assertSame('Remise', $invoice->lines[4]->title);
        $this->assertSame(63450, $invoice->total_ttc);
    }

    public function test_quote_alone_in_its_job_keeps_it_when_set_apart(): void
    {
        $this->actingAs($this->admin());
        $quote = $this->accepted([['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '1500']]);
        $jobs = app(JobCostService::class);
        $project = $jobs->projectForQuote($quote);
        $project->update(['title' => 'Maison de Massy']);

        $this->assertTrue($jobs->attachQuote($quote->fresh(), null)->is($project));
        $this->assertSame('Maison de Massy', $project->fresh()->title);
    }

    public function test_commercial_never_sees_links_to_pages_reserved_to_the_manager(): void
    {
        $this->actingAs($this->admin());
        CatalogItem::query()->create(['name' => 'Démoussage', 'unit' => 'm²', 'unit_price' => 1200, 'vat_rate' => 1000, 'is_active' => true]);
        $item = CatalogItem::query()->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('catalog.index'))->assertOk()->assertSee('Démoussage')
            ->assertDontSee(route('catalog.edit', $item))->assertDontSee(route('catalog.create'));
        $this->get(route('clients.index'))->assertOk()->assertDontSee(route('clients.import'));
        $this->get(route('requests.index'))->assertOk()->assertDontSee(route('settings.site-form'));
        $this->get(route('settings.account'))->assertOk()->assertDontSee(route('settings.journal'));
        $this->get(route('quotes.create'))->assertOk()->assertDontSee(route('settings.company'));
    }
}
