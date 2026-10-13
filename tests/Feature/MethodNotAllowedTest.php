<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Adresse d'un envoi de formulaire ouverte comme une page : jamais d'écran d'erreur anglais. */
class MethodNotAllowedTest extends TestCase
{
    use RefreshDatabase;

    public function test_reloaded_express_preview_goes_back_to_the_form_with_the_text(): void
    {
        $this->actingAs($this->admin());
        Client::factory()->create(['last_name' => 'Martin']);
        $this->post(route('quotes.express.preview'), ['text' => 'Martin, démoussage 80 m² à 12 €'])->assertOk();

        // Le téléphone rouvre la page d'aperçu (requête GET) : retour au devis express, phrase gardée.
        $this->get('/devis/express/apercu')->assertRedirect('/devis/express');
        $this->get('/devis/express')->assertOk()->assertSee('Martin, démoussage 80 m² à 12 €');
    }

    public function test_form_addresses_lead_to_the_nearest_page(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('quotes.store'), ['client_id' => Client::factory()->create()->id, 'title' => 'Toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '500']]]);
        $quote = Quote::query()->sole();

        $this->get('/devis/'.$quote->id.'/envoye')->assertRedirect('/devis/'.$quote->id);
        $this->get('/clients/importer/apercu')->assertRedirect('/clients/importer');
        $this->get('/mypos/notification')->assertRedirect('/');
    }

    public function test_other_wrong_methods_show_a_french_page(): void
    {
        $this->actingAs($this->admin());
        $this->post('/paiements')->assertStatus(405)->assertSee('Page à rouvrir')->assertDontSee('Oops');
        $this->getJson('/devis/express/apercu')->assertStatus(405);
    }
}
