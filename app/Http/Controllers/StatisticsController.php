<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPeriod;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Statistiques de provenance : d'où viennent les clients, combien de devis
 * ils génèrent et quel chiffre d'affaires ils rapportent.
 */
class StatisticsController extends Controller
{
    use ResolvesPeriod;

    public const PERIODS = [
        'mois' => 'Mois',
        'annee' => 'Année',
        'tout' => 'Depuis le début',
    ];

    public function __invoke(Request $request): View
    {
        [$period, $from, $to] = $this->period($request, 'annee');
        $end = $to->copy()->endOfDay();

        $sources = Client::withTrashed()->pluck('source', 'id');
        $sourceOf = fn ($clientId) => $sources[$clientId] ?: 'inconnue';

        $rows = collect(Client::SOURCES)->put('inconnue', 'Non renseignée')
            ->map(fn ($label) => [
                'label' => $label,
                'prospects' => 0,
                'sent' => 0,
                'accepted' => 0,
                'accepted_amount' => 0,
                'revenue' => 0,
            ]);

        $add = function (Collection $totals, string $key) use (&$rows, $sourceOf) {
            foreach ($totals as $clientId => $value) {
                $source = $sourceOf($clientId);
                if (! $rows->has($source)) {
                    $source = 'autre';
                }
                $row = $rows[$source];
                $row[$key] += (int) $value;
                $rows[$source] = $row;
            }
        };

        $add(Client::query()->whereBetween('created_at', [$from, $end])
            ->selectRaw('id, 1 as n')->pluck('n', 'id'), 'prospects');
        $add(Quote::query()->whereNotNull('sent_at')->whereBetween('sent_at', [$from, $end])
            ->groupBy('client_id')->selectRaw('client_id, COUNT(*) as n')->pluck('n', 'client_id'), 'sent');
        $accepted = Quote::query()->where('status', 'accepted')->whereBetween('accepted_at', [$from, $end]);
        $add((clone $accepted)->groupBy('client_id')->selectRaw('client_id, COUNT(*) as n')->pluck('n', 'client_id'), 'accepted');
        $add((clone $accepted)->groupBy('client_id')->selectRaw('client_id, SUM(total_ttc) as n')->pluck('n', 'client_id'), 'accepted_amount');
        $add(Invoice::query()->invoices()->issued()->where('status', '!=', 'cancelled')
            ->whereDate('issue_date', '>=', $from)->whereDate('issue_date', '<=', $to)
            ->groupBy('client_id')->selectRaw('client_id, SUM(total_ht) as n')->pluck('n', 'client_id'), 'revenue');
        $add(Invoice::query()->credits()->issued()->whereNull('cancels_id')
            ->whereDate('issue_date', '>=', $from)->whereDate('issue_date', '<=', $to)
            ->groupBy('client_id')->selectRaw('client_id, -SUM(total_ht) as n')->pluck('n', 'client_id'), 'revenue');

        $rows = $rows
            ->map(fn ($row) => $row + ['rate' => $row['sent'] ? (int) round($row['accepted'] * 100 / $row['sent']) : null])
            ->filter(fn ($row) => $row['prospects'] || $row['sent'] || $row['accepted'] || $row['revenue'])
            ->sortByDesc(fn ($row) => [$row['revenue'], $row['accepted_amount'], $row['prospects']]);

        $totals = [
            'prospects' => $rows->sum('prospects'),
            'sent' => $rows->sum('sent'),
            'accepted' => $rows->sum('accepted'),
            'accepted_amount' => $rows->sum('accepted_amount'),
            'revenue' => $rows->sum('revenue'),
        ];

        return view('statistics.index', compact('period', 'from', 'to', 'rows', 'totals'));
    }
}
