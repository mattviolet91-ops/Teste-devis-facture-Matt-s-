<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_account_is_created(): void
    {
        $this->artisan('app:create-admin', ['--name' => 'Matt Violet', '--email' => 'matt@example.com'])
            ->expectsQuestion('Mot de passe (12 caractères minimum, lettres et chiffres)', 'toiture-solide-2026')
            ->expectsQuestion('Confirmez le mot de passe', 'toiture-solide-2026')
            ->assertSuccessful();

        $user = User::query()->where('email', 'matt@example.com')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(Hash::check('toiture-solide-2026', $user->password));
    }

    public function test_weak_password_is_refused(): void
    {
        $this->artisan('app:create-admin', ['--name' => 'Matt', '--email' => 'matt@example.com'])
            ->expectsQuestion('Mot de passe (12 caractères minimum, lettres et chiffres)', 'court')
            ->expectsQuestion('Confirmez le mot de passe', 'court')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'matt@example.com']);
    }
}
