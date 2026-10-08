<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use App\Support\Money;
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

    public function test_dashboard_shows_expenses_and_gain_ttc_to_the_manager_only(): void
    {
        Expense::query()->create(['spent_on' => today(), 'label' => 'Tuiles', 'category' => 'materiaux', 'amount_ttc' => 12000, 'vat' => 2000]);
        Expense::query()->create(['spent_on' => today()->subYears(2), 'label' => 'Ancien', 'category' => 'autre', 'amount_ttc' => 99900, 'vat' => 0]);

        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()
            ->assertSee('Frais ce mois-ci (TTC)')->assertSee('Gain ce mois-ci (TTC)')
            ->assertSee(Money::format(12000))->assertSee(Money::format(-12000))
            ->assertDontSee(Money::format(99900));

        $this->actingAs(User::factory()->create(['role' => 'commercial']))->get(route('dashboard'))->assertOk()
            ->assertDontSee('Frais ce mois-ci')->assertDontSee('Gain (TTC)');
    }
}
