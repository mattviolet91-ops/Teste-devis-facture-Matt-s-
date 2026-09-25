<?php

namespace Tests\Feature;

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
}
