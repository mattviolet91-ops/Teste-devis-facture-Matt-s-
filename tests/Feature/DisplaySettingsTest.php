<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\QuoteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisplaySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bottom_bar_and_home_blocks_can_be_customised(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('dashboard'))->assertOk()->assertSee('Montant à encaisser')->assertSee('Personnaliser l\'accueil', false);
        $this->get(route('settings.display'))->assertOk()->assertSee('Barre du bas')->assertSee('Page d\'accueil', false);

        $this->put(route('settings.display'), [
            'bottom' => ['planning', 'devis', 'factures'],
            'order' => ['shortcuts', 'todo', 'kpis', 'activity', 'planning', 'payments'],
            'blocks' => ['shortcuts', 'todo'],
        ])->assertSessionHasNoErrors();

        $html = $this->get(route('dashboard'))->assertOk()
            ->assertSee('Raccourcis')->assertSee('À faire')
            ->assertDontSee('Montant à encaisser')->assertDontSee('CA facturé (HT)')->getContent();
        $this->assertLessThan(strpos($html, '<h2>À faire</h2>'), strpos($html, '<h2>Raccourcis</h2>'), 'Ordre choisi respecté.');

        // Barre du bas : Planning, Devis, [+], Factures, Plus.
        $nav = substr($html, strpos($html, 'class="bottom-nav"'));
        $this->assertMatchesRegularExpression('/Planning.*Devis.*Nouveau.*Factures.*Plus/s', substr($nav, 0, 3000));
    }

    public function test_invalid_choices_are_refused_and_reset_works(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('settings.display'), ['bottom' => ['devis', 'devis', 'clients'], 'order' => ['kpis'], 'blocks' => ['kpis']])
            ->assertSessionHasErrors('bottom.1');
        $this->put(route('settings.display'), ['bottom' => ['devis', 'planning', 'clients'], 'order' => ['kpis'], 'blocks' => []])
            ->assertSessionHasErrors('blocks');

        $this->put(route('settings.display'), ['bottom' => ['devis', 'planning', 'clients'], 'order' => ['todo'], 'blocks' => ['todo']]);
        $this->post(route('settings.display.reset'));
        $this->get(route('dashboard'))->assertSee('Montant à encaisser')->assertSee('CA facturé (HT)');
    }

    public function test_documents_button_groups_quotes_and_invoices_with_a_switch(): void
    {
        $this->actingAs($this->admin());

        // Par défaut, un seul bouton « Documents » pour les devis et les factures.
        $html = $this->get(route('dashboard'))->getContent();
        $this->assertStringContainsString(route('documents'), substr($html, strpos($html, 'class="bottom-nav"')));

        $this->get(route('documents'))->assertRedirect(route('quotes.index'));
        $this->get(route('quotes.index'))->assertOk()->assertSee('class="segmented', false)->assertSee(route('invoices.index'), false)->assertSee(route('requests.index'), false);
        $this->get(route('invoices.index'))->assertOk()->assertSee('class="segmented', false);
        // La dernière liste consultée est réouverte.
        $this->get(route('documents'))->assertRedirect(route('invoices.index'));
    }

    public function test_quote_requests_are_highlighted_in_the_switch_and_on_the_home_page(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create(['last_name' => 'Nouveau', 'city' => 'Orsay']);
        QuoteRequest::query()->create(['client_id' => $client->id, 'works' => ['demoussage'], 'status' => 'new']);

        $this->get(route('dashboard'))->assertSee('Demandes de devis (1)')->assertSee('Démoussage / nettoyage de toiture')->assertSee('Orsay');
        $this->get(route('quotes.index'))->assertSee('<span class="count-badge">1</span>', false);
        $this->get(route('requests.index'))->assertOk()->assertSee('class="segmented', false);
        $this->get(route('documents'))->assertRedirect(route('requests.index'));
    }
}
