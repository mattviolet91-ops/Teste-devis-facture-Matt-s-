<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const PLANE = 'M21 3 10 14';

    public function test_sent_badge_shows_a_plane_then_an_eye_once_the_client_has_opened_it(): void
    {
        $client = Client::factory()->create();
        $quote = Quote::query()->forceCreate(['client_id' => $client->id, 'status' => 'sent', 'number' => 'DEV-2026-0001', 'title' => 'Essai', 'validity_days' => 30]);

        $html = view('quotes._status', ['quote' => $quote])->render();
        $this->assertStringContainsString('Envoyé', $html);
        $this->assertStringNotContainsString('Vu par le client', $html);

        $quote->forceFill(['viewed_at' => now()])->save();
        $seen = view('quotes._status', ['quote' => $quote->fresh()])->render();
        $this->assertStringContainsString('Vu par le client le', $seen);
        $this->assertStringContainsString('<circle cx="12" cy="12" r="3"/>', $seen);
        $this->assertNotSame($html, $seen);

        $invoice = Invoice::query()->forceCreate(['client_id' => $client->id, 'status' => 'sent', 'kind' => 'standard', 'number' => 'FAC-2026-0001', 'due_date' => now()->addMonth(), 'viewed_at' => now()]);
        $this->assertStringContainsString('Vue par le client le', view('invoices._status', ['invoice' => $invoice])->render());
    }
}
