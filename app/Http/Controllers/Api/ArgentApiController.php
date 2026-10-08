<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;

/**
 * Pour l'app Argent (application séparée du gérant) : tous les paiements reçus
 * (factures en vigueur), tous les frais, et ce qui reste à encaisser.
 * Lecture seule ; montants en centimes TTC.
 */
class ArgentApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $payments = Payment::query()->counted()->with(['client', 'invoice'])->orderBy('id')->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'date' => $p->paid_at->toDateString(),
                'amount' => $p->amount,
                'method' => $p->method,
                'client' => $p->client?->displayName(),
                'invoice' => $p->invoice?->number,
            ]);

        $expenses = Expense::query()->orderBy('id')->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id,
                'date' => $e->spent_on->toDateString(),
                'amount' => $e->amount_ttc,
                'category' => $e->category,
                'label' => $e->label,
                'supplier' => $e->supplier,
            ]);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'payments' => $payments,
            'expenses' => $expenses,
            'summary' => [
                'to_collect' => (int) Invoice::query()->invoices()->whereIn('status', Invoice::OPEN)
                    ->selectRaw('COALESCE(SUM(total_ttc - amount_paid), 0) as due')->value('due'),
                'overdue_invoices' => Invoice::query()->overdue()->count(),
                'pending_quotes' => Quote::query()->pending()->count(),
                'pending_amount' => (int) Quote::query()->pending()->sum('total_ttc'),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
