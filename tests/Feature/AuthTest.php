<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/reglages/entreprise')->assertRedirect(route('login'));
    }

    public function test_login_page_shows_company_name(): void
    {
        $this->get(route('login'))->assertOk()->assertSee("Matt's Couverture")->assertSee('Se connecter');
    }

    public function test_user_can_log_in_and_is_logged(): void
    {
        $user = User::factory()->create(['password' => 'motdepasse-solide-123']);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'motdepasse-solide-123'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('activity_log', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->from(route('login'))
            ->post(route('login'), ['email' => $user->email, 'password' => 'mauvais'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Email ou mot de passe incorrect.']);

        $this->assertGuest();
    }

    public function test_login_is_blocked_after_five_failures(): void
    {
        $user = User::factory()->create(['password' => 'motdepasse-solide-123']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'mauvais']);
        }

        // Même le bon mot de passe est refusé pendant le blocage.
        $this->post(route('login'), ['email' => $user->email, 'password' => 'motdepasse-solide-123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Trop de tentatives', session('errors')->first('email'));
    }

    public function test_user_can_log_out(): void
    {
        $this->actingAs($this->admin())->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_reset_link_request_does_not_reveal_unknown_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->post(route('password.email'), ['email' => $user->email]);
        $unknown = $this->post(route('password.email'), ['email' => 'inconnu@example.com']);

        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nouveau-mot-de-passe-42',
            'password_confirmation' => 'nouveau-mot-de-passe-42',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('nouveau-mot-de-passe-42', $user->fresh()->password));
        $this->assertTrue(ActivityLog::query()->where('action', 'auth.password_reset')->exists());
    }

    public function test_weak_password_is_refused_on_reset(): void
    {
        $user = User::factory()->create();

        $this->post(route('password.update'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'court',
            'password_confirmation' => 'court',
        ])->assertSessionHasErrors('password');
    }
}
