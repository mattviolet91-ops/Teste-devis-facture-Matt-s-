<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        // Montants encaissés et CA : alimentés par les factures (phases 7 et 10).
        $year = now()->startOfYear();

        return view('dashboard', [
            'kpis' => [
                'to_collect' => 0,
                'pending_quotes' => Quote::query()->pending()->count(),
                'pending_amount' => (int) Quote::query()->pending()->sum('total_ttc'),
                'month_revenue' => 0,
            ],
            'stats' => [
                'accepted_quotes' => Quote::query()->where('status', 'accepted')->where('accepted_at', '>=', $year)->count(),
                'refused_quotes' => Quote::query()->where('status', 'refused')->where('refused_at', '>=', $year)->count(),
                'paid_invoices' => 0,
                'unpaid_invoices' => 0,
                'year_revenue' => 0,
            ],
        ]);
    }
}
