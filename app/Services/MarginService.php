<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Marge d'un chantier : chiffre d'affaires HT (facturé, ou prévu au devis tant
 * que rien n'est facturé) moins les achats HT rattachés au chantier.
 */
class MarginService
{
    /** @return array{quote: Quote, invoiced: int, planned: int, revenue: int, basis: string, costs: int, margin: int, rate: ?int} */
    public function forQuote(Quote $quote): array
    {
        $invoiced = (int) Invoice::query()->invoices()->issued()->where('quote_id', $quote->id)->where('status', '!=', 'cancelled')->sum('total_ht')
            - (int) Invoice::query()->credits()->issued()->where('quote_id', $quote->id)->whereNull('cancels_id')->sum('total_ht');
        $costs = (int) Expense::query()->where('quote_id', $quote->id)->selectRaw('COALESCE(SUM(amount_ttc - vat), 0) as n')->value('n');
        $revenue = $invoiced > 0 ? $invoiced : (int) $quote->total_ht;
        $margin = $revenue - $costs;

        return [
            'quote' => $quote,
            'invoiced' => $invoiced,
            'planned' => (int) $quote->total_ht,
            'revenue' => $revenue,
            'basis' => $invoiced > 0 ? 'facturé' : 'prévu au devis',
            'costs' => $costs,
            'margin' => $margin,
            'rate' => $revenue > 0 ? (int) round($margin * 100 / $revenue) : null,
        ];
    }

    /**
     * Chantiers de la période : devis acceptés sur la période, ou avec des achats sur la période.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function chantiers(Carbon $from, Carbon $to): Collection
    {
        $end = $to->copy()->endOfDay();

        return Quote::query()->whereHas('client')->with('client')
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('status', 'accepted')->whereBetween('accepted_at', [$from, $end]))
                ->orWhereHas('expenses', fn ($q) => $q->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)))
            ->latest('accepted_at')->limit(100)->get()
            ->map(fn (Quote $quote) => $this->forQuote($quote));
    }
}
