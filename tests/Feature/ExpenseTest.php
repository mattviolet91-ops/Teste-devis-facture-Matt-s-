<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\JobCostService;
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

        $this->from(route('invoices.show', $this->invoice))->delete(route('expenses.destroy', $expense))->assertRedirect(route('invoices.show', $this->invoice));
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

    public function test_expenses_are_grouped_by_job_across_deposit_and_final_invoices(): void
    {
        // Second chantier : devis de 1 000 €, facturé en partie (acompte de 30 %).
        $client = Client::factory()->create(['last_name' => 'Durand']);
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => 'Démoussage', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '1000']]])->assertSessionHasNoErrors();
        $quote = Quote::query()->latest('id')->first();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));
        $this->post(route('quotes.invoice', $quote), ['kind' => 'deposit', 'percent' => '30']);
        $deposit = Invoice::query()->latest('id')->first();
        $this->post(route('invoices.send', $deposit));

        // Frais ajouté depuis la page du chantier, et depuis la facture d'acompte.
        $this->get(route('expenses.quote', $quote))->assertOk()->assertSee('Facturé en partie (30 %)')->assertSee('Ajouter un frais');
        $this->post(route('expenses.quote.store', $quote), ['expense_label' => 'Location nacelle', 'expense_amount' => '120', 'expense_date' => '2026-01-15', 'category' => 'location'])
            ->assertRedirect(route('expenses.quote', $quote).'#ajouter');
        $this->post(route('expenses.store', $deposit), ['expense_label' => 'Produit hydrofuge', 'expense_amount' => '80'])->assertSessionHasNoErrors();

        // Facture de solde : les frais déjà notés restent sur le même chantier.
        $this->post(route('quotes.invoice', $quote), ['kind' => 'final']);
        $final = Invoice::query()->latest('id')->first();
        $this->post(route('invoices.send', $final));
        $this->get(route('invoices.show', $final))->assertSee('Location nacelle')->assertSee('Produit hydrofuge')->assertSee('Frais du chantier');

        // Mêmes frais sur chaque facture du chantier, avec la facture où chacun a été noté.
        foreach ([$deposit, $final] as $invoice) {
            $this->get(route('invoices.show', $invoice))->assertSee('href="#ajouter"', false)
                ->assertSee('Location nacelle')->assertSee('Produit hydrofuge')
                ->assertSee('noté sur facture d&#039;acompte '.$deposit->fresh()->number, false)
                ->assertSee('devis '.$quote->number);
        }
        $this->get(route('invoices.show', $final))->assertSee('facture d&#039;acompte '.$deposit->fresh()->number.'</a>', false);
        $this->get(route('invoices.show', $deposit))->assertSee('facture de solde '.$final->fresh()->number.'</a>', false);

        $page = $this->get(route('expenses.index'))->assertOk()->assertSee('Frais par chantier');
        $page->assertSee('Durand')->assertSee('Martin')->assertSee('Facturé entièrement');
        $job = app(JobCostService::class)->job($quote->fresh(), null);
        $this->assertSame([100000, 20000, 80000, true, 2], [$job['invoiced'], $job['expenses_total'], $job['remaining'], $job['fully'], $job['invoices']->count()]);
        $this->assertSame('2026-01-15', Expense::query()->where('label', 'Location nacelle')->sole()->spent_on->toDateString());

        // Recherche par client.
        $this->get(route('expenses.index', ['q' => 'Durand']))->assertSee('Durand')->assertDontSee('Réfection faîtage');

        // Le commercial n'y a pas accès.
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('expenses.index'))->assertForbidden();
        $this->get(route('expenses.quote', $quote))->assertForbidden();
    }

    public function test_job_without_quote_and_unbilled_quote(): void
    {
        $client = Client::factory()->create(['last_name' => 'Petit']);
        $this->post(route('invoices.store'), ['client_id' => $client->id, 'title' => 'Réparation fuite', 'due_days' => 0,
            'lines' => [['type' => 'item', 'title' => 'Réparation', 'quantity' => '1', 'unit_price' => '300']]])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->latest('id')->first();
        $this->post(route('invoices.send', $invoice));
        $this->post(route('expenses.store', $invoice), ['expense_label' => 'Mastic', 'expense_amount' => '20', 'retour' => 'chantier'])
            ->assertRedirect(route('expenses.invoice', $invoice).'#ajouter');
        $this->get(route('expenses.invoice', $invoice))->assertOk()->assertSee('Mastic')->assertSee('Facturé entièrement');

        // Facture d'un devis : la page « sans devis » renvoie vers le chantier du devis.
        $this->get(route('expenses.invoice', $this->invoice))->assertRedirect(route('expenses.quote', $this->invoice->quote_id));
        // Devis pas encore facturé : pas de page de frais.
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => 'Pas facturé', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'X', 'quantity' => '1', 'unit_price' => '10']]]);
        $this->get(route('expenses.quote', Quote::query()->latest('id')->first()))->assertNotFound();
    }

    public function test_expenses_can_be_noted_at_any_time(): void
    {
        // Devis accepté, pas encore facturé : déjà un chantier.
        $client = Client::factory()->create(['last_name' => 'Bernard']);
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => 'Zinguerie', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Gouttière', 'quantity' => '1', 'unit_price' => '800']]])->assertSessionHasNoErrors();
        $quote = Quote::query()->latest('id')->first();
        $this->post(route('quotes.send', $quote));
        $this->get(route('expenses.quote', $quote))->assertNotFound();
        $this->post(route('quotes.accept', $quote));

        $this->get(route('quotes.show', $quote))->assertSee('Frais du chantier')->assertSee('Pas encore facturé');
        $this->post(route('expenses.quote.store', $quote), ['expense_label' => 'Zinc', 'expense_amount' => '150', 'retour' => 'devis'])
            ->assertRedirect(route('quotes.show', $quote).'#frais');

        // « + Nouveau → Frais » : chantier choisi dans la liste, ou frais généraux.
        $this->get(route('expenses.create'))->assertOk()->assertSee('Bernard · Zinguerie (pas encore facturé)')->assertSee('Frais généraux');
        $this->post(route('expenses.store.any'), ['job' => 'devis-'.$quote->id, 'expense_label' => 'Crochets', 'expense_amount' => '30'])
            ->assertRedirect(route('expenses.quote', $quote));
        $this->post(route('expenses.store.any'), ['job' => 'general', 'expense_label' => 'Perceuse', 'expense_amount' => '99'])
            ->assertRedirect(route('expenses.general'));
        $this->post(route('expenses.store.any'), ['expense_label' => 'Sans chantier choisi', 'expense_amount' => '5'])->assertSessionHasErrors('job');

        $job = app(JobCostService::class)->job($quote->fresh(), null);
        $this->assertSame([false, 0, 18000, 62000], [$job['billed'], $job['invoiced'], $job['expenses_total'], $job['expected']]);
        $this->get(route('expenses.index'))->assertSee('En cours · pas encore facturé')->assertSee('Frais généraux');
        $this->get(route('expenses.general'))->assertSee('Perceuse')->assertSee(Money::format(9900));
        $this->assertSame(1, Expense::query()->whereNull('quote_id')->whereNull('invoice_id')->count());

        // Facturé ensuite : les frais déjà notés comptent.
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard']);
        $invoice = Invoice::query()->latest('id')->first();
        $this->post(route('invoices.send', $invoice));
        $job = app(JobCostService::class)->job($quote->fresh(), null);
        $this->assertSame([true, 80000, 62000], [$job['fully'], $job['invoiced'], $job['remaining']]);
        $this->get(route('dashboard'))->assertSee(route('expenses.create'));
    }
}
