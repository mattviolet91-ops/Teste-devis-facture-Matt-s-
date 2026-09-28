<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PushSubscription;
use App\Models\Quote;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CommercialAccountTest extends TestCase
{
    use RefreshDatabase;

    private function commercial(): User
    {
        return User::factory()->create(['role' => 'commercial', 'name' => 'Léo Vendeur']);
    }

    public function test_admin_creates_a_commercial_account_with_an_email_invitation(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);

        $this->get(route('settings.users'))->assertOk()->assertSee('Comptes d\'accès', false);
        $this->post(route('settings.users.store'), ['name' => 'Léo Vendeur', 'email' => 'leo@example.com', 'role' => 'commercial'])
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'leo@example.com')->sole();
        $this->assertTrue($user->isCommercial());
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('leo@example.com')
            && str_contains((string) $m->buttonUrl, '/reinitialiser/') && $m->buttonLabel === 'Choisir mon mot de passe');

        $this->post(route('settings.users.store'), ['name' => 'X', 'email' => 'leo@example.com', 'role' => 'commercial'])->assertSessionHasErrors('email');
    }

    public function test_without_email_the_temporary_password_is_shown_once(): void
    {
        $this->actingAs($this->admin());
        $this->followingRedirects()->post(route('settings.users.store'), ['name' => 'Léo', 'email' => 'leo@example.com', 'role' => 'commercial'])
            ->assertSee('Mot de passe provisoire de Léo');
        $this->get(route('settings.users'))->assertDontSee('Mot de passe provisoire');
    }

    public function test_commercial_sees_prospects_quotes_and_planning_but_never_invoices_or_money(): void
    {
        $client = Client::factory()->create(['last_name' => 'Prospect']);
        $invoice = Invoice::query()->forceCreate(['client_id' => $client->id, 'kind' => 'standard', 'status' => 'draft', 'due_days' => 30, 'vat_regime' => 'franchise']);
        $this->actingAs($this->commercial());

        foreach (['dashboard', 'clients.index', 'quotes.index', 'quotes.create', 'planning.index', 'requests.index', 'photos.index', 'settings.account'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('clients.show', $client))->assertOk()->assertDontSee('<h2>Factures</h2>', false);

        foreach (['invoices.index', 'invoices.create', 'payments.index', 'statistics', 'reminders.index', 'settings.company', 'settings.users', 'settings.backups', 'trash.index', 'emails.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('invoices.show', $invoice))->assertForbidden();
        $this->get(route('emails.create', ['facture' => $invoice->id]))->assertForbidden();

        $home = $this->get(route('dashboard'))->getContent();
        $this->assertStringNotContainsString('Montant à encaisser', $home);
        $this->assertStringNotContainsString('CA facturé', $home);
        $this->assertStringNotContainsString(route('invoices.index'), $home);
        $this->assertStringNotContainsString(route('settings.company'), $home);

        $this->get(route('quotes.index'))->assertDontSee(route('invoices.index'), false);
        $this->get(route('documents'))->assertRedirect(route('quotes.index'));
    }

    public function test_commercial_can_create_and_send_a_quote_but_not_invoice_it(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($this->commercial());
        $this->post(route('quotes.store'), [
            'client_id' => $client->id, 'title' => 'Démoussage', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '900']],
        ])->assertSessionHasNoErrors();
        $quote = Quote::query()->sole();
        $this->post(route('quotes.send', $quote))->assertRedirect();
        $this->post(route('quotes.accept', $quote))->assertRedirect();
        $this->get(route('quotes.show', $quote))->assertOk()->assertDontSee('data-open-sheet="invoice-dialog"', false);
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard'])->assertForbidden();
    }

    public function test_disabled_account_is_logged_out_and_cannot_log_in(): void
    {
        $admin = $this->admin();
        $commercial = User::factory()->create(['role' => 'commercial', 'email' => 'leo@example.com', 'password' => 'motdepasse-solide-1']);

        $this->actingAs($admin)->post(route('settings.users.toggle', $commercial))->assertSessionHasNoErrors();
        $this->assertNotNull($commercial->fresh()->disabled_at);

        $this->actingAs($commercial->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        auth()->logout();
        $this->post(route('login'), ['email' => 'leo@example.com', 'password' => 'motdepasse-solide-1'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($admin)->post(route('settings.users.toggle', $commercial));
        $this->assertNull($commercial->fresh()->disabled_at);
        $this->delete(route('settings.users.destroy', $admin))->assertForbidden();
        $this->delete(route('settings.users.destroy', $commercial))->assertRedirect();
        $this->assertNull(User::query()->find($commercial->id));
    }

    public function test_business_notifications_only_reach_the_admin_phones(): void
    {
        $admin = $this->admin();
        $commercial = $this->commercial();
        foreach ([$admin, $commercial] as $i => $user) {
            PushSubscription::query()->create(['user_id' => $user->id, 'endpoint' => "https://push.example/$i", 'endpoint_hash' => hash('sha256', "https://push.example/$i"), 'public_key' => 'k', 'auth_token' => 'a']);
        }

        $targets = fn (?int $userId) => PushSubscription::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('role', 'admin')->whereNull('disabled_at')))
            ->pluck('user_id')->all();
        $this->assertSame([$admin->id], $targets(null));
        $this->assertSame([$commercial->id], $targets($commercial->id));
        $this->assertTrue(method_exists(PushService::class, 'send'));
    }
}
