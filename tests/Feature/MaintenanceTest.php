<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\MaintenanceReminder;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 10:00');
        $this->client = Client::factory()->create(['civility' => 'M.', 'last_name' => 'Martin', 'phone' => '06 11 22 33 44', 'email' => 'martin@example.com']);
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sentInvoice(string $title, ?int $catalogId = null): Invoice
    {
        $this->post(route('invoices.store'), [
            'client_id' => $this->client->id, 'due_days' => 30,
            'lines' => [['type' => 'item', 'title' => $title, 'catalog_item_id' => $catalogId, 'quantity' => '1', 'unit_price' => '900']],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->post(route('invoices.send', $invoice));

        return $invoice->fresh();
    }

    public function test_sending_an_invoice_creates_a_maintenance_reminder_from_the_catalog_delay(): void
    {
        $item = CatalogItem::query()->create(['name' => 'Démoussage de toiture', 'unit' => 'm²', 'unit_price' => 1000, 'is_active' => true]);
        $this->put(route('catalog.update', $item), ['name' => 'Démoussage de toiture', 'unit' => 'm²', 'unit_price' => '10', 'maintenance_months' => '36', 'is_active' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(36, $item->fresh()->maintenance_months);

        // Ligne tapée à la main avec le même nom : reconnue aussi.
        $invoice = $this->sentInvoice('demoussage de toiture');
        $this->sentInvoice('Réparation de faîtage');

        $reminder = MaintenanceReminder::query()->sole();
        $this->assertSame('2029-10-01', $reminder->due_on->toDateString());
        $this->assertSame($invoice->id, $reminder->invoice_id);

        // Rien à relancer aujourd'hui ; dans 3 ans, oui.
        $this->get(route('maintenance.index'))->assertOk()->assertSee('Aucun entretien à proposer');
        Carbon::setTestNow('2029-10-01 09:00');
        $this->get(route('maintenance.index'))->assertSee('Martin')->assertSee('Démoussage de toiture');
        $this->get(route('maintenance.show', $reminder))->assertOk()
            ->assertSee('Bonjour Monsieur Martin,')
            ->assertSee('il y a 3 ans (octobre 2026) pour : démoussage de toiture');
        $this->get(route('emails.create', ['client' => $this->client->id, 'entretien' => $reminder->id]))->assertOk()
            ->assertSee('Entretien de votre toiture');

        $this->postJson(route('maintenance.track', $reminder), ['channel' => 'sms'])->assertOk()->assertJson(['count' => 1]);
        $this->assertSame('contacted', $reminder->fresh()->status);

        $this->put(route('maintenance.update', $reminder), ['action' => 'later'])->assertRedirect();
        $this->assertSame('2030-10-01', $reminder->fresh()->due_on->toDateString());
        $this->put(route('maintenance.update', $reminder), ['action' => 'done']);
        $this->get(route('maintenance.index', ['onglet' => 'traites']))->assertSee('Terminé');
    }

    public function test_cancelled_invoice_forgets_its_reminders_and_scan_rebuilds_history(): void
    {
        $item = CatalogItem::query()->create(['name' => 'Nettoyage des gouttières', 'unit' => 'ml', 'unit_price' => 500, 'is_active' => true, 'maintenance_months' => 12]);
        $invoice = $this->sentInvoice('Nettoyage des gouttières', $item->id);
        $this->assertSame(1, MaintenanceReminder::query()->count());

        $this->post(route('invoices.cancel', $invoice), ['reason' => 'Erreur']);
        $this->assertSame(0, MaintenanceReminder::query()->count());

        $other = $this->sentInvoice('Nettoyage des gouttières');
        MaintenanceReminder::query()->delete();
        $this->post(route('maintenance.scan'))->assertSessionHas('status');
        $this->assertSame([$other->id], MaintenanceReminder::query()->pluck('invoice_id')->all());
    }

    public function test_manual_reminder_and_morning_notification(): void
    {
        $this->post(route('maintenance.store', $this->client), ['label' => 'Démoussage', 'done_on' => '2023-10-03', 'months' => 36])
            ->assertSessionHasNoErrors();
        $this->get(route('clients.show', $this->client))->assertSee('Entretiens')->assertSee('Démoussage');

        $push = Mockery::mock(PushService::class);
        $this->app->instance(PushService::class, $push);
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body) => str_contains($title, 'Martin') && str_contains($body, '3 ans'));
        Carbon::setTestNow('2026-10-03 08:40');
        $this->artisan('app:maintenance-notifications')->assertSuccessful();
    }
}
