<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\ActivityLogger;
use App\Services\JobCostService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Frais classés par chantier (devis accepté et ses factures, ou facture sans devis) :
 * l'application calcule ce qu'il reste. Réservé au gérant, jamais montré au client.
 */
class ExpenseController extends Controller
{
    public function __construct(private readonly JobCostService $jobs) {}

    /** Tous les chantiers facturés (entièrement ou en partie), avec leurs frais. */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q')) ?: null;
        $jobs = $this->jobs->jobs($search);

        $general = $this->jobs->general();

        return view('expenses.index', [
            'jobs' => $jobs,
            'q' => $search,
            'general' => $general,
            'totals' => [
                'invoiced' => $jobs->sum('invoiced'),
                'expenses' => $jobs->sum('expenses_total'),
                'remaining' => $jobs->sum('remaining'),
            ],
        ]);
    }

    /** « + Nouveau → Frais » : noter un frais à tout moment, en choisissant le chantier. */
    public function create(Request $request): View
    {
        return view('expenses.create', [
            'jobs' => $this->jobs->jobs(),
            'selected' => (string) $request->query('chantier', ''),
        ]);
    }

    /** Enregistrement depuis « + Nouveau → Frais ». */
    public function storeAny(Request $request): RedirectResponse
    {
        $request->validate(['job' => ['required', 'string', 'regex:/^(devis-\d+|facture-\d+|general)$/']], ['job.required' => 'Choisissez le chantier (ou « Frais généraux »).'], ['job' => 'chantier']);
        [$kind, $id] = array_pad(explode('-', (string) $request->input('job')), 2, null);

        if ($kind === 'devis') {
            $quote = Quote::query()->findOrFail((int) $id);
            abort_unless($this->jobs->isJob($quote), 404);
            $this->createExpense($request, $quote->id, null, $quote);
            $back = route('expenses.quote', $quote);
        } elseif ($kind === 'facture') {
            $invoice = Invoice::query()->findOrFail((int) $id);
            abort_if($invoice->isCredit(), 404);
            $this->createExpense($request, $invoice->quote_id, $invoice->id, $invoice);
            $back = route('expenses.invoice', $invoice);
        } else {
            $this->createExpense($request, null, null, null);
            $back = route('expenses.general');
        }

        return redirect()->to($back)->with('status', 'Frais enregistré.');
    }

    /** Frais généraux : sans chantier (outillage, carburant, assurance véhicule…). */
    public function general(): View
    {
        return view('expenses.general', ['general' => $this->jobs->general()]);
    }

    public function storeGeneral(Request $request): RedirectResponse
    {
        $this->createExpense($request, null, null, null);

        return redirect()->to(route('expenses.general').'#ajouter')->with('status', 'Frais général ajouté.');
    }

    /** Frais d'un chantier fait à partir d'un devis. */
    public function quote(Quote $quote): View
    {
        abort_unless($this->jobs->isJob($quote), 404);

        return view('expenses.show', ['job' => $this->jobs->job($quote, null)]);
    }

    /** Frais d'une facture faite sans devis. */
    public function invoice(Invoice $invoice): RedirectResponse|View
    {
        if ($invoice->quote_id && $invoice->quote) {
            return redirect()->route('expenses.quote', $invoice->quote);
        }
        abort_if($invoice->isCredit() || ! in_array($invoice->status, Invoice::ISSUED, true), 404);

        return view('expenses.show', ['job' => $this->jobs->job(null, $invoice)]);
    }

    /** Ajout depuis la page du chantier (devis facturé). */
    public function storeForQuote(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless($this->jobs->isJob($quote), 404);
        $this->createExpense($request, $quote->id, null, $quote);

        return redirect()->to($request->input('retour') === 'devis' ? route('quotes.show', $quote).'#frais' : route('expenses.quote', $quote).'#ajouter')
            ->with('status', 'Frais ajouté au chantier.');
    }

    /** Ajout depuis une facture : rangé avec le chantier de cette facture. */
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->isCredit(), 404);
        $this->createExpense($request, $invoice->quote_id, $invoice->id, $invoice);

        return redirect()->to($request->input('retour') === 'chantier' ? route('expenses.invoice', $invoice).'#ajouter' : route('invoices.show', $invoice).'#frais')
            ->with('status', 'Frais ajouté au chantier.');
    }

    private function createExpense(Request $request, ?int $quoteId, ?int $invoiceId, Quote|Invoice|null $subject): Expense
    {
        $data = $request->validate([
            'expense_label' => ['required', 'string', 'max:160'],
            'expense_amount' => ['required', 'string', 'max:20'],
            'expense_vat' => ['nullable', 'string', 'max:20'],
            'expense_date' => ['nullable', 'date', 'before_or_equal:today'],
            'category' => ['nullable', Rule::in(array_keys(Expense::CATEGORIES))],
            'receipt' => ['nullable', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf'],
        ], ['expense_date.before_or_equal' => 'La date ne peut pas être dans le futur.'], ['expense_label' => 'description', 'expense_amount' => 'montant', 'expense_vat' => 'TVA', 'expense_date' => 'date', 'receipt' => 'ticket']);

        $amount = Money::parse($data['expense_amount']);
        $vat = ($data['expense_vat'] ?? '') === '' ? 0 : Money::parse($data['expense_vat']);
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['expense_amount' => 'Indiquez le montant du frais, par exemple 125,40.']);
        }
        if ($vat === null || $vat < 0 || $vat >= $amount) {
            throw ValidationException::withMessages(['expense_vat' => 'La TVA doit être inférieure au montant.']);
        }

        $expense = new Expense([
            'label' => $data['expense_label'],
            'category' => $data['category'] ?? 'materiaux',
            'amount_ttc' => $amount,
            'vat' => $vat,
            'spent_on' => $data['expense_date'] ?? today(),
            'invoice_id' => $invoiceId,
            'quote_id' => $quoteId,
        ]);
        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $expense->receipt_path = $file->store('frais/'.now()->format('Y-m'), 'local');
            $expense->receipt_name = Str::limit($file->getClientOriginalName(), 200, '');
        }
        $expense->save();
        ActivityLogger::log('expense.created', ($subject ? 'Frais : ' : 'Frais général : ').$expense->label.' ('.Money::format($amount).')', $subject);

        return $expense;
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        if ($expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
        }
        $expense->delete();

        return redirect()->back(fallback: route('expenses.index'))->with('status', 'Frais supprimé.');
    }

    /** Photo ou PDF du ticket (dossier privé). */
    public function receipt(Expense $expense): StreamedResponse
    {
        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404);
        $name = Str::of($expense->receipt_name ?? 'ticket')->ascii()->replaceMatches('/[^A-Za-z0-9._ -]/', '')->limit(100, '')->trim()->value() ?: 'ticket';

        return Storage::disk('local')->response($expense->receipt_path, $name, [
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }
}
