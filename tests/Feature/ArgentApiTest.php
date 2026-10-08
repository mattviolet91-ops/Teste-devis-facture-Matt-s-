<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ArgentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_argent_key_reads_payments_expenses_and_summary_only(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $client = Client::factory()->create(['last_name' => 'Durand', 'first_name' => 'Paul']);
        $this->post(route('invoices.store'), ['client_id' => $client->id, 'due_days' => 0,
            'lines' => [['type' => 'item', 'title' => 'Toiture', 'quantity' => '1', 'unit_price' => '1000']]])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();
        $this->post(route('invoices.send', $invoice));
        $this->post(route('payments.store', $invoice), ['amount' => '400', 'paid_at' => '2026-10-10', 'method' => 'virement'])->assertSessionHasNoErrors();
        $this->post(route('expenses.store.any'), ['job' => 'general', 'expense_label' => 'Tuiles', 'expense_amount' => '120', 'expense_date' => '2026-10-12', 'category' => 'materiaux']);

        $this->get(route('settings.api'))->assertOk()->assertSee('Créer la clé de l\'app Argent', false);
        $this->post(route('settings.api.store'), ['name' => 'App Argent', 'scope' => 'argent'])->assertSessionHas('new_api_token_scope', 'argent');
        $argent = session('new_api_token');
        $claude = ApiToken::issue($admin, 'Claude');
        auth()->logout();

        $response = $this->withToken($argent)->getJson('/api/v1/argent')->assertOk();
        $response->assertJsonPath('payments.0.amount', 40000)
            ->assertJsonPath('payments.0.date', '2026-10-10')
            ->assertJsonPath('payments.0.invoice', $invoice->fresh()->number)
            ->assertJsonPath('expenses.0.amount', 12000)
            ->assertJsonPath('expenses.0.category', 'materiaux')
            ->assertJsonPath('summary.to_collect', 60000);

        // Chaque clé reste dans sa partie.
        $this->withToken($argent)->getJson('/api/v1/clients')->assertForbidden();
        $this->withToken($claude)->getJson('/api/v1/argent')->assertForbidden();
        $this->withToken('mc_fausse')->getJson('/api/v1/argent')->assertUnauthorized();
        $this->getJson('/api/v1/argent')->assertUnauthorized();
    }

    public function test_key_of_a_commercial_or_disabled_manager_is_refused(): void
    {
        $commercial = User::factory()->create(['role' => 'commercial']);
        $plain = ApiToken::issue($commercial, 'App Argent', 'argent');
        $this->withToken($plain)->getJson('/api/v1/argent')->assertUnauthorized();
    }
}
