<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_documents_are_removed_and_numbering_restarts_but_clients_stay(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $this->post(route('quotes.store'), [
            'client_id' => $client->id, 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'A', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        $quote = Quote::query()->firstOrFail();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard']);
        $invoice = Invoice::query()->firstOrFail();
        $this->post(route('invoices.send', $invoice));
        $this->post(route('payments.store', $invoice), ['amount' => '100', 'paid_at' => today()->toDateString(), 'method' => 'cb']);
        $this->post(route('invoices.cancel', $invoice));

        $this->artisan('app:reset-documents')->expectsOutputToContain('Rien n\'a été supprimé');
        $this->assertSame(1, Quote::query()->count());

        $this->artisan('app:reset-documents', ['--confirmer' => true])->assertSuccessful();

        $this->assertSame(0, Quote::withTrashed()->count());
        $this->assertSame(0, Invoice::withTrashed()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertModelExists($client);
        $this->assertSame('complete', app(BackupService::class)->list()->first()['type'], 'Sauvegarde faite avant.');

        $this->post(route('quotes.store'), [
            'client_id' => $client->id, 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'B', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        $new = Quote::query()->firstOrFail();
        $this->post(route('quotes.send', $new));
        $this->assertSame('DEV-'.now()->year.'-0001', $new->fresh()->number);
    }
}
