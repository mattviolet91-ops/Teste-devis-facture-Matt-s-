<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Support\Collection;

/**
 * Frais classés par chantier. Un chantier, c'est un devis accepté et toutes ses
 * factures (acompte, situation, solde…), ou une facture faite sans devis.
 * Seuls les chantiers facturés (entièrement ou en partie) apparaissent.
 */
class JobCostService
{
    /**
     * Tous les chantiers facturés, du plus récent au plus ancien.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function jobs(?string $search = null): Collection
    {
        $invoices = Invoice::query()
            ->whereIn('status', Invoice::ISSUED)
            ->where('kind', '!=', 'credit')
            ->with(['client', 'worksite', 'quote.worksite'])
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->search($search)->orWhereHas('quote', fn ($qq) => $qq->search($search))))
            ->get();

        $expenses = Expense::query()->get(['id', 'quote_id', 'invoice_id', 'amount_ttc', 'vat']);
        $byQuote = $expenses->whereNotNull('quote_id')->groupBy('quote_id');
        $byInvoice = $expenses->whereNull('quote_id')->groupBy('invoice_id');

        $jobs = $invoices->groupBy(fn (Invoice $i) => $i->quote_id ? 'q'.$i->quote_id : 'i'.$i->id)
            ->map(function (Collection $group) use ($byQuote, $byInvoice) {
                $first = $group->sortBy('id')->first();
                $quote = $first->quote_id ? $first->quote : null;
                $jobExpenses = $quote ? ($byQuote[$quote->id] ?? collect()) : ($byInvoice[$first->id] ?? collect());

                return $this->summary($quote, $quote ? null : $first, $group, $jobExpenses) + [
                    'last' => $group->max('issue_date') ?? $group->max('created_at'),
                ];
            })
            ->sortByDesc('last')
            ->values();

        return $jobs;
    }

    /** Un chantier : devis et ses factures, ou facture seule. */
    public function job(?Quote $quote, ?Invoice $invoice): array
    {
        $invoices = $quote
            ? $quote->invoices()->whereIn('status', Invoice::ISSUED)->get()
            : collect([$invoice]);
        $expenses = Expense::query()
            ->when($quote, fn ($q) => $q->where('quote_id', $quote->id), fn ($q) => $q->whereNull('quote_id')->where('invoice_id', $invoice->id))
            ->with('invoice')
            ->latest('spent_on')->latest('id')
            ->get();

        return $this->summary($quote, $invoice, $invoices, $expenses) + ['expenses' => $expenses, 'invoices' => $invoices];
    }

    /** Chantier d'une facture (pour la section « Frais » de la page facture). */
    public function forInvoice(Invoice $invoice): array
    {
        return $invoice->quote_id && $invoice->quote
            ? $this->job($invoice->quote, null)
            : $this->job(null, $invoice);
    }

    /** @return array<string, mixed> */
    private function summary(?Quote $quote, ?Invoice $invoice, Collection $invoices, Collection $expenses): array
    {
        $document = $quote ?? $invoice;
        $invoiced = (int) $invoices->sum('total_ht');
        $planned = $quote ? (int) $quote->total_ht : $invoiced;
        $costs = (int) $expenses->sum(fn (Expense $e) => $e->amountHt());

        return [
            'key' => $quote ? 'devis-'.$quote->id : 'facture-'.$invoice->id,
            'quote' => $quote,
            'invoice' => $invoice,
            'client' => $document->client,
            'title' => $document->title ?: ($quote ? 'Devis '.$quote->number : 'Facture '.$invoice->number),
            'address' => ($document->worksite ?? null)?->fullAddress(),
            'numbers' => $invoices->pluck('number')->filter()->implode(', '),
            'planned' => $planned,
            'invoiced' => $invoiced,
            'fully' => $invoiced >= $planned,
            'percent' => $planned > 0 ? min(100, (int) round($invoiced * 100 / $planned)) : 100,
            'expenses_total' => $costs,
            'expenses_count' => $expenses->count(),
            'remaining' => $invoiced - $costs,
            'url' => $quote ? route('expenses.quote', $quote) : route('expenses.invoice', $invoice),
        ];
    }
}
