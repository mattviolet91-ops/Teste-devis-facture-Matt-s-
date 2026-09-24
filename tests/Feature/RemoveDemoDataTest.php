<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoveDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_demo_clients_and_demo_account_are_removed(): void
    {
        $admin = User::factory()->create(['email' => 'mv.entreprise91@gmail.com', 'role' => 'admin']);
        $this->seed(DemoSeeder::class);
        $real = Client::factory()->create(['last_name' => 'Vrai', 'email' => 'vrai.client@gmail.com', 'phone' => '06 00 00 00 01']);
        $demoCount = Client::query()->count() - 1;
        $this->assertGreaterThan(20, $demoCount);

        $this->artisan('app:remove-demo-data')->expectsOutputToContain('Rien n\'a été supprimé');
        $this->assertSame($demoCount + 1, Client::query()->count());

        $this->artisan('app:remove-demo-data', ['--confirmer' => true])->assertSuccessful();

        $this->assertSame([$real->id], Client::withTrashed()->pluck('id')->all());
        $this->assertNull(User::query()->where('email', 'demo@example.com')->first());
        $this->assertModelExists($admin);
    }
}
