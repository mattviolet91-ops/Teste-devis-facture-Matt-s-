<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guide_explains_every_page_the_account_can_open(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('guide'));
        $this->get(route('guide'))->assertOk()
            ->assertSee('Faire un devis')->assertSee('Devis express (en une phrase)')->assertSee('Factures')
            ->assertSee('Réglages')->assertSee('href="'.route('settings.company').'"', false);
    }

    public function test_a_commercial_account_sees_only_the_parts_it_can_use(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('guide'))->assertOk()
            ->assertSee('Faire un devis')->assertSee('Planning et rendez-vous')
            ->assertDontSee('Relances de factures')->assertDontSee('Ouvrir les paiements')->assertDontSee('Ouvrir les réglages');
    }
}
