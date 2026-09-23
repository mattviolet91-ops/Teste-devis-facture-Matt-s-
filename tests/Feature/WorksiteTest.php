<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Worksite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorksiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_worksite_can_be_added_with_roof_details(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->admin())->post(route('worksites.store', $client), [
            'label' => 'Résidence Les Érables — bât. A',
            'address' => '18 allée des Érables',
            'postal_code' => '91300',
            'city' => 'Massy',
            'contact_phone' => '0698765432',
            'roof_type' => 'ardoise',
            'roof_surface' => '420,5',
            'roof_pitch' => '45°',
            'levels' => 5,
            'accessibility' => 'nacelle',
        ])->assertSessionHasNoErrors();

        $worksite = $client->worksites()->firstOrFail();
        $this->assertSame('420.50', $worksite->roof_surface);
        $this->assertSame('06 98 76 54 32', $worksite->contact_phone);

        $this->get(route('clients.show', $client))->assertSee('420,5 m²')->assertSee('Ardoise')->assertSee('Nacelle');
    }

    public function test_worksite_requires_an_address(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->admin())->post(route('worksites.store', $client), ['city' => 'Massy'])
            ->assertSessionHasErrors(['address', 'postal_code']);
    }

    public function test_worksite_can_be_edited_and_trashed_then_restored(): void
    {
        $admin = $this->admin();
        $worksite = Worksite::factory()->create(['address' => '1 rue Ancienne']);

        $this->actingAs($admin)->put(route('worksites.update', $worksite), [
            'address' => '2 rue Nouvelle', 'postal_code' => '91300', 'city' => 'Massy',
        ])->assertSessionHasNoErrors();
        $this->assertSame('2 rue Nouvelle', $worksite->fresh()->address);

        $this->actingAs($admin)->delete(route('worksites.destroy', $worksite));
        $this->assertSoftDeleted($worksite);

        $this->actingAs($admin)->post(route('trash.worksites.restore', $worksite->id));
        $this->assertNotSoftDeleted($worksite);
    }

    public function test_worksite_of_trashed_client_cannot_be_edited(): void
    {
        $worksite = Worksite::factory()->create();
        $worksite->client->delete();

        $this->actingAs($this->admin())->get(route('worksites.edit', $worksite))->assertNotFound();
    }
}
