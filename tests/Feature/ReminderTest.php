<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 08:30');
        Mail::fake();
        $this->client = Client::factory()->create(['civility' => 'Mme', 'last_name' => 'Dupont', 'phone' => '06 12 34 56 78']);
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(int $dueDays): Invoice
    {
        $this->post(route('invoices.store'), [
            'client_id' => $this->client->id, 'due_days' => $dueDays,
            'lines' => [['type' => 'item', 'title' => 'Réparation', 'quantity' => '1', 'unit_price' => '850']],
        ]);
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->post(route('invoices.send', $invoice));

        return $invoice->fresh();
    }

    public function test_reminder_page_offers_ready_text_with_due_date(): void
    {
        $invoice = $this->invoice(15);

        $this->get(route('reminders.show', $invoice))->assertOk()
            ->assertSee('Rappel avant échéance')
            ->assertSee('arrive à échéance le 16/10/2026')
            ->assertSee('Bonjour Madame Dupont,');

        Carbon::setTestNow('2026-10-21 10:00');
        $this->get(route('reminders.show', $invoice))->assertOk()
            ->assertSee('Message de relance')
            ->assertSee('échéance : 16/10/2026, en retard de 5 jours')
            ->assertSee('data-phone="33612345678"', false)
            ->assertSee('850,00 €');
        $this->get(route('reminders.index'))->assertSee('retard 5 j');
    }

    public function test_each_reminder_is_counted_and_logged(): void
    {
        $invoice = $this->invoice(0);

        $this->post(route('reminders.track', $invoice), ['channel' => 'whatsapp'])->assertOk()->assertJson(['count' => 1]);
        $this->post(route('reminders.track', $invoice), ['channel' => 'sms'])->assertOk()->assertJson(['count' => 2]);
        $this->post(route('reminders.track', $invoice), ['channel' => 'pigeon'])->assertSessionHasErrors('channel');

        $this->assertSame(2, $invoice->fresh()->reminder_count);
        $this->assertDatabaseHas('activity_log', ['description' => "Relance de la facture {$invoice->number} par WhatsApp"]);
        $this->get(route('invoices.show', $invoice))->assertSee('Relancer (2)');
    }

    public function test_paid_invoice_has_no_reminder_page(): void
    {
        $invoice = $this->invoice(0);
        $this->post(route('payments.store', $invoice), ['amount' => '850', 'paid_at' => '2026-10-01', 'method' => 'cb']);

        $this->get(route('reminders.show', $invoice))->assertNotFound();
    }

    public function test_morning_notification_on_due_date_steps(): void
    {
        $invoice = $this->invoice(10); // échéance le 11/10
        $push = Mockery::mock(PushService::class);
        $this->app->instance(PushService::class, $push);

        // J-3 : notification.
        Carbon::setTestNow('2026-10-08 08:30');
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body, $url) => str_contains($title, 'Dupont') && str_contains($body, 'échéance dans 3 jours') && $url === route('reminders.show', $invoice));
        $this->artisan('app:reminder-notifications')->assertSuccessful();

        // J-2 : rien.
        Carbon::setTestNow('2026-10-09 08:30');
        $this->artisan('app:reminder-notifications')->expectsOutputToContain('Aucune facture');

        // J+7 : notification de retard.
        Carbon::setTestNow('2026-10-18 08:30');
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body) => str_contains($body, 'en retard de 7 jours'));
        $this->artisan('app:reminder-notifications')->assertSuccessful();
    }

    public function test_email_summary_when_no_phone_is_subscribed(): void
    {
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
        $this->invoice(0);

        $this->artisan('app:reminder-notifications')->assertSuccessful();
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->mailSubject === 'Factures à relancer aujourd\'hui' && str_contains($m->text, 'échéance aujourd\'hui'));
    }
}
