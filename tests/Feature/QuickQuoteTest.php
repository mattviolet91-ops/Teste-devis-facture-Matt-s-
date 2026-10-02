<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuickQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickQuoteTest extends TestCase
{
    use RefreshDatabase;

    private Client $martin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->martin = Client::factory()->create(['civility' => 'Mme', 'last_name' => 'Martin', 'first_name' => null, 'company_name' => null, 'type' => 'particulier', 'city' => 'Massy']);
        Client::factory()->create(['last_name' => 'Martinez', 'first_name' => null, 'company_name' => null, 'type' => 'particulier', 'city' => 'Palaiseau']);
        CatalogItem::query()->create(['name' => 'Remplacement d\'une faîtière', 'description' => 'Dépose et repose', 'unit' => 'u', 'unit_price' => 15000, 'vat_rate' => 1000, 'is_active' => true]);
        CatalogItem::query()->create(['name' => 'Réfection complète de la toiture en tuiles', 'description' => 'Faîtage compris', 'unit' => 'm²', 'unit_price' => 18000, 'vat_rate' => 1000, 'is_active' => true]);
    }

    private const TEXT = 'Mme Martin Massy — démoussage 120 m² à 12 € — remplacement faîtière x3 à 150 € — évacuation des déchets forfait 150 €';

    public function test_a_sentence_becomes_a_draft_quote_after_preview(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('quotes.express'))->assertOk()->assertSee('Devis express')->assertSee('href="'.route('quotes.index').'" data-back', false);
        $this->get(route('quotes.index'))->assertSee(route('quotes.express'));
        $this->get(route('quotes.create'))->assertSee(route('quotes.express'));
        $this->get(route('dashboard'))->assertDontSee('> Devis express</a>', false);

        $this->post(route('quotes.express.preview'), ['text' => self::TEXT])->assertOk()
            ->assertSee('Mme Martin')->assertSee('Remplacement d&#039;une faîtière', false)->assertSee('Bibliothèque')
            ->assertSee('Créer le brouillon');
        $this->assertSame(0, Quote::query()->count());

        $this->post(route('quotes.express.store'), ['text' => self::TEXT, 'client_id' => $this->martin->id])->assertRedirect();
        $quote = Quote::query()->with('lines')->sole();
        $this->assertSame(['draft', $this->martin->id], [$quote->status, $quote->client_id]);
        $this->assertSame([144000, 45000, 15000], $quote->lines->pluck('total_ht')->all());
        $this->assertSame(['Démoussage', 'Remplacement d\'une faîtière', 'Évacuation des déchets'], $quote->lines->pluck('title')->all());
        $this->assertSame([120000, 3000, 1000], $quote->lines->pluck('quantity')->all());
        $this->assertSame(204000, $quote->total_ht);
    }

    public function test_missing_price_or_unknown_client_is_never_guessed(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('quotes.express.preview'), ['text' => 'Mme Martin — nettoyage gouttières 20 ml'])
            ->assertSee('prix manquant')->assertDontSee('Créer le brouillon');
        $this->post(route('quotes.express.preview'), ['text' => 'Dupont Inconnu — démoussage 10 m² à 12 €'])
            ->assertSee('Client introuvable')->assertSee('Créer la fiche client');
        // « Faîtage » ne doit pas être confondu avec une autre prestation qui le cite seulement dans sa description.
        $this->post(route('quotes.express.preview'), ['text' => 'Martin — faîtage 15 ml à 45 €'])->assertDontSee('Réfection complète');
        $this->post(route('quotes.express.store'), ['text' => 'Mme Martin — nettoyage gouttières 20 ml']);
        $this->assertSame(0, Quote::query()->count());
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: ?int}> titre, quantité, unité, prix unitaire */
    private function lines(string $text): array
    {
        $parsed = app(QuickQuoteService::class)->parse($text);
        $this->assertSame($this->martin->id, $parsed['client']?->id, 'Client non trouvé pour : '.$text);

        return array_map(fn ($l) => [$l['title'], $l['quantity'], $l['unit'], $l['unit_price_cents']], $parsed['lines']);
    }

    public function test_dictated_sentences_are_read_correctly(): void
    {
        // Virgules et points comme séparateurs, unités en toutes lettres.
        $this->assertSame([['Démoussage', '120', 'm²', 1200], ['Remplacement d\'une faîtière', '3', 'u', 15000]],
            $this->lines('Madame Martin à Massy. Démoussage 120 mètres carrés à 12 euros. Remplacement de 3 faîtières à 150 euros.'));
        // Client et première prestation dans la même phrase, « et » entre deux prestations.
        $this->assertSame([['Démoussage', '120', 'm²', 1200], ['Évacuation', '1', 'forfait', 15000]],
            $this->lines('Devis pour Mme Martin démoussage 120 m2 à 12€ et évacuation forfait 150€'));
        // « x3 150 € » : 3 à 150 €, jamais 3 150 €.
        $this->assertSame([['Faîtière', '3', 'u', 15000], ['Démoussage', '12.5', 'm²', 1200]],
            $this->lines('Mme Martin — faîtière x3 150 € — démoussage 12,5 m² à 12 € HT'));
        // Prix « le mètre linéaire », « de l'heure », milliers avec espace, mots gardés dans la désignation.
        $this->assertSame([['Nettoyage et vérification des gouttières', '20', 'ml', 800], ['Main d\'oeuvre', '8', 'h', 4500], ['Pose de velux', '2', 'u', 150000], ['Démoussage', '1200', 'm²', 350]],
            $this->lines("Martin\nnettoyage des gouttières 20 ml à 8 € le mètre linéaire\nmain d'oeuvre 8 h à 45 € de l'heure\npose de 2 velux à 1 500 €\ndémoussage 1 200 m² à 3,50 € le m²"));
        // Mots en plus du nom de la bibliothèque : reconnue, mais les mots sont gardés.
        $line = app(QuickQuoteService::class)->parse('Martin, remplacement faîtière côté rue x2 à 150 €')['lines'][0];
        $this->assertSame(['Remplacement faîtière côté rue', true], [$line['title'], $line['recognized']]);
    }

    public function test_a_vague_word_never_picks_a_library_price(): void
    {
        CatalogItem::query()->create(['name' => 'Traitement de la charpente', 'unit' => 'm²', 'unit_price' => 2500, 'vat_rate' => 1000, 'is_active' => true]);
        CatalogItem::query()->create(['name' => 'Traitement hydrofuge de la toiture', 'unit' => 'm²', 'unit_price' => 900, 'vat_rate' => 1000, 'is_active' => true]);

        $line = app(QuickQuoteService::class)->parse('Mme Martin — traitement 80 m² à 8 € du m²')['lines'][0];
        $this->assertSame(['Traitement', '80', 'm²', 800, false], [$line['title'], $line['quantity'], $line['unit'], $line['unit_price_cents'], $line['recognized']]);
        $this->assertStringContainsString('proche de votre bibliothèque', $line['warnings'][0]);

        $parsed = app(QuickQuoteService::class)->parse('Mme Martin — traitement hydrofuge de la toiture 80 m²');
        $this->assertSame([900, true, []], [$parsed['lines'][0]['unit_price_cents'], $parsed['lines'][0]['recognized'], $parsed['errors']]);
        $this->assertSame(['Martinez', 'Mme Martin'], [
            app(QuickQuoteService::class)->parse('Martinez recherche de fuite forfait 180 €')['client']?->last_name,
            app(QuickQuoteService::class)->parse('Martin Massy, recherche de fuite forfait 180 €')['client']?->displayName(),
        ]);
    }

    public function test_commercial_can_use_quick_quotes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->post(route('quotes.express.store'), ['text' => self::TEXT, 'client_id' => $this->martin->id])->assertRedirect();
        $this->assertSame(1, Quote::query()->count());
    }

    public function test_claude_creates_drafts_with_an_access_key_only(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->post(route('settings.api.store'), ['name' => 'Claude'])->assertRedirect(route('settings.api'));
        $plain = session('new_api_token');
        $this->assertStringStartsWith('mc_', $plain);
        $this->assertNotSame($plain, ApiToken::query()->sole()->token_hash);
        $this->get(route('settings.api'))->assertSee('Accès Claude');
        auth()->logout();

        $this->getJson('/api/v1/clients?q=martin')->assertUnauthorized();
        $this->withToken('mc_faux')->getJson('/api/v1/clients?q=martin')->assertUnauthorized();

        $api = $this->withToken($plain);
        $api->getJson('/api/v1/clients?q=martin')->assertOk()->assertJsonFragment(['nom' => 'Mme Martin', 'ville' => 'Massy']);
        $api->getJson('/api/v1/prestations?q=faitiere')->assertOk()->assertJsonFragment(['nom' => 'Remplacement d\'une faîtière']);

        $api->postJson('/api/v1/devis/apercu', ['texte' => self::TEXT])->assertOk()->assertJsonPath('client_trouve.nom', 'Mme Martin');
        $this->assertSame(0, Quote::query()->count());

        $refused = $api->postJson('/api/v1/devis', ['texte' => 'Martin — gouttières 20 ml'])->assertStatus(422);
        $this->assertStringContainsString('prix manquant', $refused->json('erreurs.0'));
        $this->assertSame(0, Quote::query()->count());
        $created = $api->postJson('/api/v1/devis', ['texte' => self::TEXT, 'client_id' => $this->martin->id, 'objet' => 'Entretien de toiture'])
            ->assertCreated()->assertJsonPath('statut', 'brouillon')->assertJsonPath('objet', 'Entretien de toiture');
        $quote = Quote::query()->sole();
        $this->assertSame(['draft', $admin->id, 204000], [$quote->status, $quote->created_by, $quote->total_ht]);
        $this->assertStringContainsString('/devis/'.$quote->id, $created->json('lien'));

        // Clé révoquée : plus d'accès.
        $this->actingAs($admin)->delete(route('settings.api.destroy', ApiToken::query()->sole()));
        auth()->logout();
        $this->withToken($plain)->getJson('/api/v1/clients?q=martin')->assertUnauthorized();
    }

    public function test_claude_creates_a_complete_draft_with_sections_texts_and_options(): void
    {
        $plain = ApiToken::issue($this->admin(), 'Claude');
        $api = $this->withToken($plain);

        $api->postJson('/api/v1/devis/complet', ['client_id' => $this->martin->id, 'lines' => [['type' => 'item', 'title' => '']]])
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.title', 'lines.0.quantity']);
        $api->postJson('/api/v1/devis/complet', ['client_id' => $this->martin->id])->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->assertSame(0, Quote::query()->count());

        $api->postJson('/api/v1/devis/complet', [
            'client_id' => $this->martin->id,
            'title' => 'Isolation de la toiture — 3 solutions',
            'notes' => 'Chiffrage communiqué à part.',
            'lines' => [
                ['type' => 'text', 'description' => 'Trois solutions au choix.'],
                ['type' => 'section', 'title' => 'Solution 1 — PIR', 'hide_prices' => true],
                ['type' => 'item', 'title' => 'Panneaux PIR 120 mm', 'description' => 'λ 0,022, R ≈ 5,45', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '', 'is_optional' => true],
            ],
        ])->assertCreated()->assertJsonPath('statut', 'brouillon')->assertJsonPath('lignes', 3)->assertJsonPath('objet', 'Isolation de la toiture — 3 solutions');

        $quote = Quote::query()->sole();
        $lines = $quote->lines()->orderBy('position')->get();
        $this->assertSame(['draft', 30, 'Chiffrage communiqué à part.'], [$quote->status, $quote->validity_days, $quote->notes]);
        $this->assertSame(['text', 'section', 'item'], $lines->pluck('type')->all());
        $this->assertTrue($lines[1]->hide_prices);
        $this->assertTrue($lines[2]->is_optional);
        $this->assertSame('λ 0,022, R ≈ 5,45', $lines[2]->description);
        $this->actingAs($this->admin())->get(route('quotes.edit', $quote))->assertOk()->assertSee('Panneaux PIR 120 mm');
    }

    public function test_access_key_of_a_disabled_or_commercial_account_is_refused(): void
    {
        $commercial = User::factory()->create(['role' => 'commercial']);
        $plain = ApiToken::issue($commercial, 'Essai');
        $this->withToken($plain)->getJson('/api/v1/clients?q=martin')->assertUnauthorized();

        $this->actingAs($commercial)->get(route('settings.api'))->assertForbidden();
    }
}
