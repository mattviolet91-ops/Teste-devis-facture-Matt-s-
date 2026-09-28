<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\MarginService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Quote $quote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->admin());
        $client = Client::factory()->create(['last_name' => 'Martin']);
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => 'Réfection faîtage', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Faîtage', 'quantity' => '1', 'unit_price' => '2000']]])->assertSessionHasNoErrors();
        $this->quote = Quote::query()->sole();
        $this->post(route('quotes.send', $this->quote));
        $this->post(route('quotes.accept', $this->quote));
    }

    public function test_purchase_with_receipt_lowers_the_margin_of_the_worksite(): void
    {
        $this->get(route('quotes.show', $this->quote))->assertOk()->assertSee('Achats et marge')->assertSee('prévu au devis');

        $this->post(route('expenses.store'), [
            'label' => 'Tuiles faîtières', 'supplier' => 'Point.P', 'amount' => '450,50', 'spent_on' => today()->toDateString(),
            'category' => 'materiaux', 'quote_id' => $this->quote->id,
            'receipt' => UploadedFile::fake()->image('ticket.jpg'),
        ])->assertRedirect(route('quotes.show', $this->quote).'#marge')->assertSessionHasNoErrors();

        $expense = Expense::query()->sole();
        $this->assertSame([45050, 0], [$expense->amount_ttc, $expense->vat]);
        Storage::disk('local')->assertExists($expense->receipt_path);
        $this->get(route('expenses.receipt', $expense))->assertOk();

        // Devis 2 000 € (franchise de TVA) − 450,50 € d'achats = 1 549,50 €, soit 77 %.
        $this->get(route('quotes.show', $this->quote))->assertSee(Money::format(154950))->assertSee('77 %');
        $this->get(route('expenses.index'))->assertOk()->assertSee('Marge par chantier')->assertSee('Tuiles faîtières')->assertSee('ticket joint');

        // Une fois facturé, la marge se calcule sur le facturé.
        $this->post(route('quotes.invoice', $this->quote), ['kind' => 'standard']);
        $this->post(route('invoices.send', Invoice::query()->sole()));
        $margin = app(MarginService::class)->forQuote($this->quote->fresh());
        $this->assertSame(['facturé', 200000, 45050, 154950], [$margin['basis'], $margin['revenue'], $margin['costs'], $margin['margin']]);
    }

    public function test_invalid_amounts_are_refused_and_purchase_can_be_edited_and_deleted(): void
    {
        $this->post(route('expenses.store'), ['label' => 'Gasoil', 'amount' => 'abc', 'spent_on' => today()->toDateString(), 'category' => 'carburant'])
            ->assertSessionHasErrors('amount');

        $this->post(route('expenses.store'), ['label' => 'Gasoil', 'amount' => '60', 'spent_on' => today()->toDateString(), 'category' => 'carburant'])
            ->assertRedirect(route('expenses.index'));
        $expense = Expense::query()->sole();
        $this->assertNull($expense->quote_id);

        $this->get(route('expenses.edit', $expense))->assertOk()->assertSee('60,00');
        $this->put(route('expenses.update', $expense), ['label' => 'Gasoil camion', 'amount' => '65,20', 'spent_on' => today()->toDateString(), 'category' => 'carburant'])
            ->assertSessionHasNoErrors();
        $this->assertSame(6520, $expense->fresh()->amount_ttc);

        $this->delete(route('expenses.destroy', $expense))->assertRedirect(route('expenses.index'));
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_commercial_cannot_see_purchases_or_margins(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'commercial']));

        $this->get(route('expenses.index'))->assertForbidden();
        $this->get(route('expenses.create'))->assertForbidden();
        $this->get(route('quotes.show', $this->quote))->assertOk()->assertDontSee('Achats et marge');
    }
}
