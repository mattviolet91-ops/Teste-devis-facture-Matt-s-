<?php

namespace Tests\Feature;

use App\Http\Middleware\RestoreExpiredForm;
use App\Models\Client;
use App\Models\Quote;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_form_arrived_without_its_content_goes_back_to_the_page_with_a_warning(): void
    {
        $this->actingAs($this->admin());

        // Enregistrement des réglages arrivé vide (sans « modification ») : retour sur la page.
        $this->post('/reglages/entreprise')->assertStatus(303)->assertRedirect('/reglages/entreprise?envoi=incomplet');
        $this->get('/reglages/entreprise?envoi=incomplet')->assertOk()->assertSee("L'envoi n'est pas arrivé en entier", false);
        $this->post('/paiements')->assertRedirect('/paiements?envoi=incomplet');
        $this->getJson('/devis/express/apercu')->assertStatus(405);
    }

    public function test_empty_submission_with_expired_token_is_not_called_kept(): void
    {
        $request = Request::create('/reglages/entreprise', 'POST');
        $request->headers->set('referer', url('/reglages/entreprise'));
        $request->setLaravelSession($this->app['session.store']);

        $response = $this->app->make(ExceptionHandler::class)->render($request, new HttpException(419));

        $this->assertSame(url('/reglages/entreprise').'?envoi=incomplet', $response->headers->get('Location'));
        $this->assertNull($this->app['session.store']->get(RestoreExpiredForm::KEY));
    }
}
