<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
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
        $request->validate(['job' => ['required', 'string', 'regex:/^(chantier-\d+|general)$/']], ['job.required' => 'Choisissez le chantier (ou « Frais généraux »).'], ['job' => 'chantier']);

        if ($request->input('job') === 'general') {
            $this->createExpense($request, null, null, null, null);

            return redirect()->route('expenses.general')->with('status', 'Frais enregistré.');
        }

        $project = Project::query()->findOrFail((int) substr((string) $request->input('job'), 9));
        $this->createExpense($request, $project, null, null, $project);

        return redirect()->route('expenses.project', $project)->with('status', 'Frais enregistré.');
    }

    /** Frais généraux : sans chantier (outillage, carburant, assurance véhicule…). */
    public function general(): View
    {
        return view('expenses.general', ['general' => $this->jobs->general()]);
    }

    public function storeGeneral(Request $request): RedirectResponse
    {
        $this->createExpense($request, null, null, null, null);

        return redirect()->to(route('expenses.general').'#ajouter')->with('status', 'Frais général ajouté.');
    }

    /** Page d'un chantier : ses devis, ses factures et ses frais. */
    public function project(Project $project): View
    {
        $job = $this->jobs->job($project);

        return view('expenses.show', ['job' => $job]);
    }

    /** Ajout depuis la page du chantier. */
    public function storeForProject(Request $request, Project $project): RedirectResponse
    {
        $this->createExpense($request, $project, null, null, $project);

        return redirect()->to(route('expenses.project', $project).'#ajouter')->with('status', 'Frais ajouté au chantier.');
    }

    /** Nom du chantier (ex. « Toiture de la maison » quand il regroupe plusieurs devis). */
    public function renameProject(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:160']], [], ['title' => 'nom du chantier']);
        $project->update(['title' => $data['title']]);

        return redirect()->route('expenses.project', $project)->with('status', 'Chantier renommé.');
    }

    /** Ancien lien « chantier d'un devis » : page du chantier de ce devis. */
    public function quote(Quote $quote): RedirectResponse
    {
        abort_unless($this->jobs->isJob($quote), 404);

        return redirect()->route('expenses.project', $this->jobs->projectForQuote($quote));
    }

    /** Ancien lien « chantier d'une facture » : page du chantier de cette facture. */
    public function invoice(Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->isCredit(), 404);

        return redirect()->route('expenses.project', $this->jobs->projectForInvoice($invoice));
    }

    /** Ajout depuis le devis accepté. */
    public function storeForQuote(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless($this->jobs->isJob($quote), 404);
        $project = $this->jobs->projectForQuote($quote);
        $this->createExpense($request, $project, $quote->id, null, $quote);

        return redirect()->to($request->input('retour') === 'devis' ? route('quotes.show', $quote).'#frais' : route('expenses.project', $project).'#ajouter')
            ->with('status', 'Frais ajouté au chantier.');
    }

    /** Ajout depuis une facture : rangé avec le chantier de cette facture. */
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->isCredit(), 404);
        $project = $this->jobs->projectForInvoice($invoice);
        $this->createExpense($request, $project, $invoice->quote_id, $invoice->id, $invoice);

        return redirect()->to($request->input('retour') === 'chantier' ? route('expenses.project', $project).'#ajouter' : route('invoices.show', $invoice).'#frais')
            ->with('status', 'Frais ajouté au chantier.');
    }

    /** Range un devis dans un autre chantier du même client (ou dans un chantier à part). */
    public function attachQuote(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless($this->jobs->isJob($quote), 404);
        $target = $this->target($request, $quote->client_id);
        $project = $this->jobs->attachQuote($quote, $target);

        return redirect()->to(url()->previous().'#frais')->with('status', 'Devis rangé dans le chantier « '.$project->title.' ».');
    }

    public function attachInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->isCredit() || $invoice->quote_id, 404);
        $target = $this->target($request, $invoice->client_id);
        $project = $this->jobs->attachInvoice($invoice, $target);

        return redirect()->to(url()->previous().'#frais')->with('status', 'Facture rangée dans le chantier « '.$project->title.' ».');
    }

    /** Chantier choisi : un chantier du même client, ou « à part » (null). */
    private function target(Request $request, ?int $clientId): ?Project
    {
        $data = $request->validate(['project' => ['required', 'string', 'regex:/^(\d+|apart)$/']], [], ['project' => 'chantier']);
        if ($data['project'] === 'apart') {
            return null;
        }

        return Project::query()->whereKey((int) $data['project'])->where('client_id', $clientId)->firstOrFail();
    }

    private function createExpense(Request $request, ?Project $project, ?int $quoteId, ?int $invoiceId, Quote|Invoice|Project|null $subject): Expense
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
            'project_id' => $project?->id,
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
