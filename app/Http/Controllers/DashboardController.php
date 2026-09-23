<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        // Les indicateurs seront alimentés par les devis, factures et
        // paiements (phases 6 à 10). Ils sont à zéro en attendant.
        return view('dashboard', [
            'kpis' => [
                'to_collect' => 0,
                'pending_quotes' => 0,
                'month_revenue' => 0,
            ],
            'stats' => [
                'accepted_quotes' => 0,
                'refused_quotes' => 0,
                'paid_invoices' => 0,
                'unpaid_invoices' => 0,
                'year_revenue' => 0,
            ],
        ]);
    }
}
