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
            ->assertSee('CA facturé du mois');
    }

    public function test_upcoming_modules_show_their_phase(): void
    {
        $this->actingAs($this->admin())
            ->get(route('module', 'paiements'))
            ->assertOk()
            ->assertSee('phase 10');
    }
}
