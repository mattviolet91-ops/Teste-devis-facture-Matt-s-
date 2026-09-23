<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SecurityPhase12Test extends TestCase
{
    use RefreshDatabase;

    private function login(User $user, string $agent): void
    {
        $this->withHeader('User-Agent', $agent)
            ->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        auth()->logout();
    }

    public function test_login_from_a_new_device_sends_an_alert(): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => 'admin', 'email' => 'mv.entreprise91@gmail.com']);
        $this->actingAs($user)->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
        auth()->logout();

        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1';
        $this->login($user, $iphone);
        Mail::assertNothingSent();

        $this->login($user, $iphone);
        Mail::assertNothingSent();

        $this->login($user, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36');
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('mv.entreprise91@gmail.com')
            && str_contains($m->text, 'un ordinateur Windows (Chrome)'));
    }

    public function test_other_devices_can_be_logged_out_with_password(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $this->post(route('settings.account.logout-others'), ['current_password' => 'mauvais'])->assertSessionHasErrors('current_password');
        $this->post(route('settings.account.logout-others'), ['current_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_log', ['action' => 'account.logout_others']);
    }

    public function test_activity_journal_lists_actions_and_logins(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->login($user, 'TestAgent');
        $this->actingAs($user);

        $this->get(route('settings.journal'))->assertOk()->assertSee('Connexion');
        $this->get(route('settings.journal', ['connexions' => 1]))->assertOk()->assertSee('Connexion');
        $this->assertGreaterThan(0, ActivityLog::query()->count());
    }

    public function test_journal_requires_login(): void
    {
        $this->get(route('settings.journal'))->assertRedirect(route('login'));
    }
}
