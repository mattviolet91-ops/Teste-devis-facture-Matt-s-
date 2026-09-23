<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\InsuranceService;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(InsuranceService $insurance): View
    {
        $year = now()->startOfYear();
        $unpaid = Invoice::query()->invoices()->where('status', 'sent');

        return view('dashboard', [
            'insuranceAlert' => $insurance->message(),
            'insuranceLevel' => $insurance->level(),
            'kpis' => [
                'to_collect' => (int) (clone $unpaid)->selectRaw('COALESCE(SUM(total_ttc - amount_paid), 0) as due')->value('due'),
                'pending_quotes' => Quote::query()->pending()->count(),
                'pending_amount' => (int) Quote::query()->pending()->sum('total_ttc'),
                'month_revenue' => $this->revenueSince(now()->startOfMonth()),
            ],
            'stats' => [
                'accepted_quotes' => Quote::query()->where('status', 'accepted')->where('accepted_at', '>=', $year)->count(),
                'refused_quotes' => Quote::query()->where('status', 'refused')->where('refused_at', '>=', $year)->count(),
                'paid_invoices' => Invoice::query()->invoices()->where('status', 'paid')->whereDate('issue_date', '>=', $year)->count(),
                'unpaid_invoices' => (clone $unpaid)->count(),
                'overdue_invoices' => Invoice::query()->overdue()->count(),
                'year_revenue' => $this->revenueSince($year),
            ],
        ]);
    }

    /**
     * Chiffre d'affaires HT facturé depuis une date : factures émises (même
     * annulées ensuite) moins les avoirs émis sur la même période.
     */
    private function revenueSince(Carbon $from): int
    {
        $invoiced = (int) Invoice::query()->invoices()->issued()->whereDate('issue_date', '>=', $from)->sum('total_ht');
        $credited = (int) Invoice::query()->credits()->issued()->whereDate('issue_date', '>=', $from)->sum('total_ht');

        return $invoiced - $credited;
    }
}
