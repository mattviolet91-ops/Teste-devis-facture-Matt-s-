<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ProvidesEditorData;
use App\Http\Requests\InvoiceRequest;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\ActivityLogger;
use App\Services\InvoiceService;
use App\Services\Settings;
use App\Support\Percent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    use ProvidesEditorData;

    public const FILTERS = [
        'all' => 'Toutes',
        'draft' => 'Brouillons',
        'unpaid' => 'À encaisser',
        'overdue' => 'En retard',
        'paid' => 'Payées',
        'credit' => 'Avoirs',
        'cancelled' => 'Annulées',
    ];

    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(self::FILTERS))],
        ]);
        $filters['status'] ??= 'all';

        $invoices = Invoice::query()
            ->with('client')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->search($q))
            ->when($filters['status'], fn ($query, $status) => match ($status) {
                'draft' => $query->where('status', 'draft'),
                'unpaid' => $query->invoices()->whereIn('status', Invoice::OPEN),
                'overdue' => $query->overdue(),
                'paid' => $query->invoices()->where('status', 'paid'),
                'credit' => $query->credits(),
                'cancelled' => $query->where('status', 'cancelled'),
                // Liste principale : sans les factures annulées ni leurs avoirs.
                default => $query->where('status', '!=', 'cancelled')
                    ->where(fn ($q) => $q->where('kind', '!=', 'credit')->orWhereNull('cancels_id')),
            })
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('invoices.index', compact('invoices', 'filters'));
    }

    public function create(Request $request, Settings $settings): View
    {
        $client = $request->integer('client') ? Client::query()->find($request->integer('client')) : null;
        $invoice = $this->invoices->blank($client?->id, $client?->worksites()->value('id'));

        return view('invoices.edit', ['invoice' => $invoice] + $this->editorData($invoice, $settings));
    }

    public function store(InvoiceRequest $request): RedirectResponse
    {
        $invoice = $this->invoices->saveDraft($this->invoices->blank(), $request->invoiceAttributes(), $request->lines());
        ActivityLogger::log('invoice.created', 'Facture créée (brouillon)', $invoice);

        return redirect()->route('invoices.show', $invoice)->with('status', 'Brouillon enregistré.');
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['client', 'worksite', 'lines', 'quote', 'cancels', 'corrects', 'creditNote', 'correction', 'payments']);

        $history = ActivityLog::query()
            ->where('subject_type', $invoice->getMorphClass())
            ->where('subject_id', $invoice->id)
            ->latest('id')
            ->limit(20)
            ->get();

        return view('invoices.show', [
            'invoice' => $invoice,
            'totals' => $this->invoices->breakdown($invoice),
            'problems' => $invoice->isDraft() ? $this->invoices->sendingProblems($invoice) : [],
            'history' => $history,
        ]);
    }

    public function edit(Invoice $invoice, Settings $settings): View|RedirectResponse
    {
        if (! $invoice->isDraft() || $invoice->isCredit()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('status', 'Une facture envoyée ne se modifie plus directement : utilisez « Modifier » (avoir + facture corrigée).');
        }

        return view('invoices.edit', ['invoice' => $invoice->load('lines')] + $this->editorData($invoice, $settings));
    }

    public function update(InvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->isDraft() && ! $invoice->isCredit(), 403, 'Seul un brouillon peut être modifié.');

        $this->invoices->saveDraft($invoice, $request->invoiceAttributes(), $request->lines());
        ActivityLogger::log('invoice.updated', 'Brouillon de facture modifié', $invoice);

        return redirect()->route('invoices.show', $invoice)->with('status', 'Brouillon enregistré.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->isDraft(), 403, 'Seul un brouillon peut être supprimé : une facture envoyée est conservée.');

        $invoice->delete();
        ActivityLogger::log('invoice.deleted', 'Brouillon de facture mis à la corbeille', $invoice);

        return redirect()->route('invoices.index')->with('status', 'Brouillon placé dans la corbeille.');
    }

    public function send(Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->isDraft(), 403);

        $problems = $this->invoices->sendingProblems($invoice->load('lines'));
        if ($problems !== []) {
            return back()->withErrors(['send' => $problems[0]]);
        }

        $this->invoices->send($invoice);

        return redirect()->route('invoices.show', $invoice)->with('status', "Facture {$invoice->number} marquée comme envoyée.");
    }

    /** « Modifier » une facture envoyée : avoir d'annulation + brouillon corrigé. */
    public function correct(Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->isCorrectable(), 403);

        $number = $invoice->number;
        $draft = $this->invoices->correct($invoice);

        return redirect()->route('invoices.edit', $draft)
            ->with('status', "Avoir {$invoice->creditNote()->value('number')} émis pour annuler la facture {$number}. Corrigez la facture ci-dessous : elle recevra un nouveau numéro à l'envoi.");
    }

    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->isCorrectable(), 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        $credit = $this->invoices->cancel($invoice, $data['reason'] ?? null);

        return redirect()->route('invoices.index')->with('status', "Facture {$invoice->number} supprimée : annulée par l'avoir {$credit->number} (visible dans le filtre « Annulées »).");
    }

    /** Facturation d'un devis accepté (acompte, situation, solde, facture complète). */
    public function fromQuote(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless($quote->isInvoiceable(), 403, 'Seul un devis accepté peut être facturé.');

        $data = $request->validate([
            'kind' => ['required', Rule::in(['deposit', 'progress', 'final', 'standard'])],
            'percent' => [Rule::requiredIf(in_array($request->input('kind'), ['deposit', 'progress'], true)), 'nullable', 'string', 'max:10'],
        ], ['percent.required' => 'Indiquez le pourcentage à facturer.']);

        $percent = null;
        if (in_array($data['kind'], ['deposit', 'progress'], true)) {
            $percent = Percent::parse($data['percent']);
            if (! $percent || $percent > 10000) {
                return back()->withErrors(['percent' => 'Pourcentage invalide (ex. 40 ou 33,5).']);
            }
        }

        $invoice = $this->invoices->createFromQuote($quote, $data['kind'], $percent);

        return redirect()->route('invoices.edit', $invoice)->with('status', 'Brouillon préparé depuis le devis : vérifiez-le puis enregistrez.');
    }
}
