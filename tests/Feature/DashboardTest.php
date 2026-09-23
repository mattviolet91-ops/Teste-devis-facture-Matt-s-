<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_the_three_key_figures(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Montant à encaisser')
            ->assertSee('Devis en attente de réponse')
            ->assertSee('CA facturé (HT)');
    }

    public function test_period_filters_change_the_activity(): void
    {
        $this->actingAs($this->admin());

        foreach (['jour', 'semaine', 'mois', 'annee'] as $period) {
            $this->get(route('dashboard', ['periode' => $period]))->assertOk();
        }
        $this->get(route('dashboard', ['periode' => 'perso', 'du' => '2026-01-01', 'au' => '2026-03-31']))
            ->assertOk()->assertSee('du 01/01/2026 au 31/03/2026');
        $this->get(route('dashboard', ['periode' => 'perso', 'du' => 'nimporte', 'au' => 'quoi']))->assertOk()->assertSee('ce mois-ci');
    }
}
