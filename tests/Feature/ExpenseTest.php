<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\PdfService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->admin());
        $client = Client::factory()->create(['last_name' => 'Martin']);
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => 'Réfection faîtage', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Faîtage', 'quantity' => '1', 'unit_price' => '2000']]])->assertSessionHasNoErrors();
        $quote = Quote::query()->sole();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard']);
        $this->invoice = Invoice::query()->sole();
        $this->post(route('invoices.send', $this->invoice));
    }

    public function test_expenses_on_the_invoice_compute_what_remains(): void
    {
        $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Frais')->assertSee('Visible par vous seul')->assertSee('Il vous reste');

        $this->post(route('expenses.store', $this->invoice), [
            'expense_label' => 'Tuiles faîtières', 'expense_amount' => '450,50', 'receipt' => UploadedFile::fake()->image('ticket.jpg'),
        ])->assertRedirect(route('invoices.show', $this->invoice).'#frais')->assertSessionHasNoErrors();
        $this->post(route('expenses.store', $this->invoice), ['expense_label' => 'Déchetterie', 'expense_amount' => '49,50'])->assertSessionHasNoErrors();

        // Facture 2 000 € (franchise de TVA) − 500 € de frais = 1 500 € qui restent, soit 75 %.
        $this->assertSame(150000, $this->invoice->fresh()->remainingAfterExpenses());
        $this->get(route('invoices.show', $this->invoice))->assertSee(Money::format(150000))->assertSee('75 %')->assertSee('Tuiles faîtières');

        $expense = Expense::query()->where('label', 'Tuiles faîtières')->sole();
        Storage::disk('local')->assertExists($expense->receipt_path);
        $this->get(route('expenses.receipt', $expense))->assertOk();

        $this->delete(route('expenses.destroy', $expense))->assertRedirect(route('invoices.show', $this->invoice).'#frais');
        Storage::disk('local')->assertMissing($expense->receipt_path);
        $this->assertSame(195050, $this->invoice->fresh()->remainingAfterExpenses());
    }

    public function test_expenses_never_reach_the_client(): void
    {
        $this->post(route('expenses.store', $this->invoice), ['expense_label' => 'Frais secret du chantier', 'expense_amount' => '300']);

        $invoice = $this->invoice->fresh();
        $html = view('pdf.document', (fn () => $this->viewData($invoice))->call(app(PdfService::class)))->render();
        $this->assertStringNotContainsString('Frais secret', $html);
        $this->assertStringNotContainsString('Il vous reste', $html);

        auth()->logout();
        $this->get(route('portal.invoice', basename($invoice->publicUrl())))
            ->assertOk()->assertDontSee('Frais secret')->assertDontSee('Il vous reste');
    }

    public function test_invalid_amounts_are_refused_and_commercial_has_no_access(): void
    {
        $this->post(route('expenses.store', $this->invoice), ['expense_label' => 'Gasoil', 'expense_amount' => 'abc'])->assertSessionHasErrors('expense_amount');
        $this->assertSame(0, Expense::query()->count());

        $this->post(route('expenses.store', $this->invoice), ['expense_label' => 'Gasoil', 'expense_amount' => '60']);
        $expense = Expense::query()->sole();

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->post(route('expenses.store', $this->invoice), ['expense_label' => 'X', 'expense_amount' => '1'])->assertForbidden();
        $this->get(route('expenses.receipt', $expense))->assertForbidden();
        $this->delete(route('expenses.destroy', $expense))->assertForbidden();
        $this->assertSame(1, Expense::query()->count());
    }
}
