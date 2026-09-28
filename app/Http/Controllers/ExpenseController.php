<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPeriod;
use App\Models\Expense;
use App\Models\Quote;
use App\Services\ActivityLogger;
use App\Services\MarginService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Achats (photo du ticket) et marge par chantier — réservé au gérant. */
class ExpenseController extends Controller
{
    use ResolvesPeriod;

    public const PERIODS = ['mois' => 'Ce mois', 'annee' => 'Cette année', 'tout' => 'Depuis le début'];

    public function index(Request $request, MarginService $margins): View
    {
        [$period, $from, $to] = $this->period($request, 'mois');

        $expenses = Expense::query()->with('quote.client')
            ->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)
            ->latest('spent_on')->latest('id')->get();

        return view('expenses.index', [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'expenses' => $expenses,
            'total' => $expenses->sum(fn (Expense $e) => $e->amountHt()),
            'totalTtc' => $expenses->sum('amount_ttc'),
            'byCategory' => $expenses->groupBy('category')->map(fn ($list) => $list->sum(fn (Expense $e) => $e->amountHt()))->sortDesc(),
            'chantiers' => $margins->chantiers($from, $to),
        ]);
    }

    public function create(Request $request): View
    {
        $expense = new Expense(['spent_on' => today(), 'category' => 'materiaux', 'quote_id' => $request->integer('devis') ?: null]);

        return view('expenses.form', ['expense' => $expense, 'quotes' => $this->quotes($expense)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $expense = new Expense($this->validated($request));
        $this->storeReceipt($request, $expense);
        $expense->save();
        ActivityLogger::log('expense.created', 'Achat : '.$expense->label.' ('.Money::format($expense->amount_ttc).')', $expense->quote?->client);

        return $this->redirectAfterSave($expense, 'Achat enregistré.');
    }

    public function edit(Expense $expense): View
    {
        return view('expenses.form', ['expense' => $expense, 'quotes' => $this->quotes($expense)]);
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $expense->fill($this->validated($request));
        $this->storeReceipt($request, $expense);
        $expense->save();

        return $this->redirectAfterSave($expense, 'Achat modifié.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        if ($expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
        }
        $quote = $expense->quote;
        $expense->delete();

        return ($quote ? redirect()->route('quotes.show', $quote) : redirect()->route('expenses.index'))->with('status', 'Achat supprimé.');
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

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'spent_on' => ['required', 'date'],
            'label' => ['required', 'string', 'max:160'],
            'supplier' => ['nullable', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'amount' => ['required', 'string', 'max:20'],
            'vat' => ['nullable', 'string', 'max:20'],
            'quote_id' => ['nullable', 'integer', Rule::exists('quotes', 'id')],
            'receipt' => ['nullable', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf'],
        ], [], ['spent_on' => 'date', 'label' => 'description', 'supplier' => 'fournisseur', 'category' => 'catégorie',
            'amount' => 'montant', 'vat' => 'TVA', 'quote_id' => 'chantier', 'receipt' => 'ticket']);

        $amount = Money::parse($data['amount']);
        $vat = ($data['vat'] ?? '') === '' ? 0 : Money::parse($data['vat']);
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Indiquez le montant payé, par exemple 125,40.']);
        }
        if ($vat === null || $vat < 0 || $vat >= $amount) {
            throw ValidationException::withMessages(['vat' => 'La TVA doit être inférieure au montant payé.']);
        }

        return ['amount_ttc' => $amount, 'vat' => $vat] + collect($data)->only(['spent_on', 'label', 'supplier', 'category', 'quote_id'])->all();
    }

    private function storeReceipt(Request $request, Expense $expense): void
    {
        if (! $request->hasFile('receipt')) {
            return;
        }
        if ($expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
        }
        $file = $request->file('receipt');
        $expense->receipt_path = $file->store('achats/'.now()->format('Y-m'), 'local');
        $expense->receipt_name = Str::limit($file->getClientOriginalName(), 200, '');
    }

    private function redirectAfterSave(Expense $expense, string $message): RedirectResponse
    {
        return ($expense->quote_id ? redirect()->to(route('quotes.show', $expense->quote_id).'#marge') : redirect()->route('expenses.index'))
            ->with('status', $message);
    }

    /** Chantiers proposés : devis acceptés (les plus récents) + celui déjà choisi. */
    private function quotes(Expense $expense)
    {
        return Quote::query()->with('client')->whereHas('client')
            ->where(fn ($q) => $q->where('status', 'accepted')->orWhere('id', $expense->quote_id))
            ->latest('accepted_at')->limit(80)->get();
    }
}
