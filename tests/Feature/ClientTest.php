<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Worksite;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_pages_require_login(): void
    {
        $client = Client::factory()->create();

        $this->get(route('clients.index'))->assertRedirect(route('login'));
        $this->get(route('clients.show', $client))->assertRedirect(route('login'));
        $this->post(route('clients.store'), [])->assertRedirect(route('login'));
    }

    public function test_individual_client_is_created_with_worksite_at_same_address(): void
    {
        $response = $this->actingAs($this->admin())->post(route('clients.store'), [
            'type' => 'particulier',
            'civility' => 'Mme',
            'first_name' => 'Hélène',
            'last_name' => 'Dupont',
            'phone' => '+33 6 12 34 56 78',
            'email' => ' Helene.Dupont@Example.com ',
            'address' => '12 rue des Tilleuls',
            'postal_code' => '91300',
            'city' => 'Massy',
            'create_worksite' => '1',
        ]);

        $client = Client::query()->firstOrFail();
        $response->assertRedirect(route('clients.show', $client));

        $this->assertSame('Mme Hélène Dupont', $client->displayName());
        $this->assertSame('06 12 34 56 78', $client->phone, 'Le numéro est remis au format français.');
        $this->assertSame('helene.dupont@example.com', $client->email);
        $this->assertSame('prospect', $client->status);
        $this->assertCount(1, $client->worksites);
        $this->assertSame('12 rue des Tilleuls, 91300 Massy', $client->worksites->first()->fullAddress());
        $this->assertDatabaseHas('activity_log', ['action' => 'client.created']);
    }

    public function test_worksite_is_not_created_when_unchecked(): void
    {
        $this->actingAs($this->admin())->post(route('clients.store'), [
            'type' => 'particulier', 'last_name' => 'Martin',
            'address' => '1 rue A', 'postal_code' => '91300', 'city' => 'Massy',
            'create_worksite' => '0',
        ]);

        $this->assertSame(0, Worksite::query()->count());
    }

    public function test_individual_requires_last_name_and_professional_requires_company(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('clients.store'), ['type' => 'particulier'])
            ->assertSessionHasErrors(['last_name' => 'Le nom est obligatoire pour un particulier.']);

        $this->actingAs($admin)->post(route('clients.store'), ['type' => 'syndic', 'last_name' => 'Martin'])
            ->assertSessionHasErrors(['company_name' => 'Le nom de la société est obligatoire.']);
    }

    public function test_professional_client_displays_company_and_contact(): void
    {
        $this->actingAs($this->admin())->post(route('clients.store'), [
            'type' => 'syndic', 'company_name' => 'Cabinet Martin Gestion',
            'civility' => 'M.', 'first_name' => 'Julien', 'last_name' => 'Martin',
        ]);

        $client = Client::query()->firstOrFail();
        $this->assertSame('Cabinet Martin Gestion', $client->displayName());
        $this->assertSame('M. Julien Martin', $client->contactName());
        $this->assertSame('CM', $client->initials());

        $this->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('Cabinet Martin Gestion')
            ->assertSee('Contact : M. Julien Martin');
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('clients.store'), ['type' => 'particulier', 'last_name' => 'X', 'phone' => 'appeler le soir'])
            ->assertSessionHasErrors('phone');
    }

    public function test_client_can_be_updated_and_change_is_logged(): void
    {
        $client = Client::factory()->create(['last_name' => 'Durand']);

        $this->actingAs($this->admin())->put(route('clients.update', $client), [
            'type' => 'particulier', 'last_name' => 'Durand', 'first_name' => 'Paul',
            'status' => 'client', 'phone' => '0699887766',
        ])->assertRedirect(route('clients.show', $client));

        $client->refresh();
        $this->assertSame('client', $client->status);
        $this->assertSame('06 99 88 77 66', $client->phone);
        $this->assertDatabaseHas('activity_log', ['action' => 'client.updated', 'subject_id' => $client->id]);
    }

    public function test_client_page_shows_worksites_quick_actions_and_history(): void
    {
        $client = Client::factory()->create(['phone' => '06 12 34 56 78', 'email' => 'a@example.com']);
        Worksite::factory()->for($client)->create(['address' => '5 rue du Four', 'access_notes' => 'Digicode 1234']);

        $this->actingAs($this->admin())->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('tel:+33612345678', false)
            ->assertSee('mailto:a@example.com', false)
            ->assertSee('5 rue du Four')
            ->assertSee('Digicode 1234')
            ->assertSee('google.com/maps', false);
    }

    public function test_client_list_filters_by_type_and_status(): void
    {
        Client::factory()->create(['last_name' => 'Particulier-Prospect']);
        Client::factory()->company('syndic')->customer()->create(['company_name' => 'Syndic Client']);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('clients.index', ['type' => 'syndic']))
            ->assertSee('Syndic Client')->assertDontSee('Particulier-Prospect');
        $this->actingAs($admin)->get(route('clients.index', ['status' => 'prospect']))
            ->assertSee('Particulier-Prospect')->assertDontSee('Syndic Client');
    }

    public function test_client_list_search_includes_worksite_addresses(): void
    {
        $client = Client::factory()->create(['last_name' => 'Lambert', 'address' => '1 rue Ailleurs', 'city' => 'Orsay']);
        Worksite::factory()->for($client)->create(['address' => '18 allée des Érables', 'city' => 'Massy']);
        Client::factory()->create(['last_name' => 'Rousseau']);

        $this->actingAs($this->admin())->get(route('clients.index', ['q' => 'erables']))
            ->assertSee('Lambert')->assertDontSee('Rousseau');
    }

    public function test_client_list_is_paginated(): void
    {
        Client::factory()->count(30)->create();

        $this->actingAs($this->admin())->get(route('clients.index'))
            ->assertOk()->assertSee('Page 1 / 2')->assertSee('30 fiches');
    }

    public function test_client_goes_to_trash_and_can_be_restored(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create(['last_name' => 'Corbeille']);

        $this->actingAs($admin)->delete(route('clients.destroy', $client))->assertRedirect(route('clients.index'));
        $this->assertSoftDeleted($client);
        $this->actingAs($admin)->get(route('clients.show', $client))->assertNotFound();
        $this->actingAs($admin)->get(route('trash.index'))->assertSee('Corbeille');

        $this->actingAs($admin)->post(route('trash.clients.restore', $client->id))->assertRedirect(route('clients.show', $client));
        $this->assertNotSoftDeleted($client);
        $this->assertDatabaseHas('activity_log', ['action' => 'client.restored']);
    }

    public function test_trash_is_purged_after_thirty_days(): void
    {
        $old = Client::factory()->create();
        $recent = Client::factory()->create();
        Worksite::factory()->for($old)->create();

        $this->travelTo(now()->subDays(31), fn () => $old->delete());
        $this->travelTo(now()->subDays(5), fn () => $recent->delete());

        $this->artisan('app:purge-trash')->assertSuccessful();

        $this->assertDatabaseMissing('clients', ['id' => $old->id]);
        $this->assertDatabaseMissing('worksites', ['client_id' => $old->id]);
        $this->assertSoftDeleted($recent);
    }

    public function test_purge_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command);

        $this->assertTrue($events->contains(fn ($command) => str_contains((string) $command, 'app:purge-trash')));
    }
}
