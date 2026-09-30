<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
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

    public function test_access_key_of_a_disabled_or_commercial_account_is_refused(): void
    {
        $commercial = User::factory()->create(['role' => 'commercial']);
        $plain = ApiToken::issue($commercial, 'Essai');
        $this->withToken($plain)->getJson('/api/v1/clients?q=martin')->assertUnauthorized();

        $this->actingAs($commercial)->get(route('settings.api'))->assertForbidden();
    }
}
