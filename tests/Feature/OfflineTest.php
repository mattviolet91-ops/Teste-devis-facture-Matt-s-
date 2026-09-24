<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_page_is_public_and_service_worker_is_loaded_in_the_app(): void
    {
        $this->get(route('offline'))->assertOk()->assertSee('Pas de réseau');

        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()
            ->assertSee('js/offline.js', false)
            ->assertSee('data-offline-root', false);
        $this->get(route('clients.create'))->assertSee('data-offline="Nouveau client"', false);
        $this->get(route('settings.account'))->assertSee('Télécharger pour le hors connexion');
    }

    public function test_token_and_page_list_require_login(): void
    {
        $this->get(route('offline.token'))->assertRedirect(route('login'));
        $this->get(route('offline.pages'))->assertRedirect(route('login'));

        $client = Client::factory()->create();
        $this->actingAs($this->admin());
        $this->getJson(route('offline.token'))->assertOk()->assertJsonStructure(['token'])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(route('offline.pages'))->assertOk()->assertJsonFragment([route('clients.show', $client->id)]);
    }

    public function test_client_portal_does_not_load_the_offline_script(): void
    {
        $this->get(route('offline'))->assertDontSee('js/offline.js', false);
    }
}
