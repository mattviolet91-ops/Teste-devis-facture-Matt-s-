<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\JobCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Planning : chantier, devis et facture du client choisi. */
class PlanningLinksTest extends TestCase
{
    use RefreshDatabase;

    private function acceptedQuote(Client $client, string $title): Quote
    {
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => $title, 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Travaux', 'quantity' => '1', 'unit_price' => '500']]])->assertSessionHasNoErrors();
        $quote = Quote::query()->latest('id')->first();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));

        return $quote->fresh();
    }

    public function test_appointment_links_the_job_quote_and_invoice_of_the_client(): void
    {
        $this->actingAs($this->admin());
        $martin = Client::factory()->create(['last_name' => 'Martin']);
        $other = Client::factory()->create(['last_name' => 'Autre']);
        $quote = $this->acceptedQuote($martin, 'Toiture');
        $foreign = $this->acceptedQuote($other, 'Ailleurs');
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard']);
        $invoice = Invoice::query()->sole();
        $this->post(route('invoices.send', $invoice));
        $project = app(JobCostService::class)->projectForQuote($quote);

        // Les listes sont données client par client.
        $page = $this->get(route('planning.create', ['type' => 'rdv']))->assertOk()->assertSee('Adresse du chantier')->assertSee('data-planning-invoice', false);
        $links = $page->viewData('links');
        $this->assertSame([$quote->id], $links[$martin->id]['quotes']->pluck('id')->all());
        $this->assertSame([$foreign->id], $links[$other->id]['quotes']->pluck('id')->all());
        $this->assertSame([$project->id], $links[$martin->id]['projects']->pluck('id')->all());

        $form = ['kind' => 'rdv', 'client_id' => $martin->id, 'title' => 'Visite', 'starts_on' => '2026-11-03', 'start_time' => '10:00'];
        // Devis choisi sans chantier : le chantier du devis est retenu.
        $this->post(route('planning.store'), $form + ['quote_id' => $quote->id, 'invoice_id' => $invoice->fresh()->id])->assertSessionHasNoErrors();
        $rdv = Intervention::query()->latest('id')->first();
        $this->assertSame([$project->id, $quote->id, $invoice->id], [$rdv->project_id, $rdv->quote_id, $rdv->invoice_id]);
        $this->get(route('planning.show', $rdv))->assertSee($project->title)->assertSee($invoice->fresh()->number);

        // Devis d'un autre client : refusé.
        $this->post(route('planning.store'), $form + ['quote_id' => $foreign->id])->assertSessionHasErrors('quote_id');

        // Commercial : pas de facture.
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('planning.create', ['type' => 'rdv']))->assertOk()->assertDontSee('data-planning-invoice', false);
        $this->post(route('planning.store'), $form + ['invoice_id' => $invoice->id])->assertSessionHasNoErrors();
        $this->assertNull(Intervention::query()->latest('id')->first()->invoice_id);
    }
}
