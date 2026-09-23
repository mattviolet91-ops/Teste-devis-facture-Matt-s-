<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Worksite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $dupont = Client::factory()->create([
            'first_name' => 'Hélène', 'last_name' => 'Dupont', 'phone' => '06 12 34 56 78',
            'email' => 'helene@example.com', 'city' => 'Massy',
        ]);
        Worksite::factory()->for($dupont)->create(['address' => '12 rue des Tilleuls', 'city' => 'Massy']);

        Client::factory()->company('syndic')->create([
            'company_name' => 'Cabinet Martin Gestion', 'first_name' => 'Julien', 'last_name' => 'Martin',
            'phone' => '01 69 00 11 22', 'email' => 'gestion@cabinet.example', 'city' => 'Palaiseau',
        ]);
    }

    private function search(string $q)
    {
        return $this->actingAs($this->admin())->get(route('search', ['q' => $q]));
    }

    public function test_search_ignores_accents_and_case(): void
    {
        $this->search('HELENE')->assertSee('Hélène Dupont')->assertDontSee('Cabinet Martin');
    }

    public function test_search_by_partial_phone_in_any_format(): void
    {
        $this->search('0612')->assertSee('Hélène Dupont');
        $this->search('06 12 34')->assertSee('Hélène Dupont');
        $this->search('+33 6 12 34 56 78')->assertSee('Hélène Dupont');
        $this->search('0169')->assertSee('Cabinet Martin Gestion');
    }

    public function test_search_by_email_city_and_company(): void
    {
        $this->search('gestion@cabinet')->assertSee('Cabinet Martin Gestion');
        $this->search('palaiseau')->assertSee('Cabinet Martin Gestion')->assertDontSee('Hélène');
        $this->search('cabinet martin')->assertSee('Cabinet Martin Gestion');
    }

    public function test_search_finds_worksites_by_address(): void
    {
        $this->search('tilleuls')->assertSee('12 rue des Tilleuls')->assertSee('Chantiers');
    }

    public function test_all_words_must_match(): void
    {
        $this->search('helene palaiseau')->assertSee('Aucun résultat');
    }

    public function test_like_wildcards_are_neutralised(): void
    {
        $this->search('%')->assertDontSee('Hélène Dupont');
    }

    public function test_trashed_clients_are_not_found(): void
    {
        Client::query()->where('last_name', 'Dupont')->first()->delete();

        $this->search('dupont')->assertSee('Aucun résultat');
        $this->search('tilleuls')->assertSee('Aucun résultat');
    }

    public function test_partial_results_for_live_search(): void
    {
        $this->actingAs($this->admin())->get(route('search', ['q' => 'dupont', 'partial' => 1]))
            ->assertOk()
            ->assertSee('Hélène Dupont')
            ->assertDontSee('<html', false);
    }
}
