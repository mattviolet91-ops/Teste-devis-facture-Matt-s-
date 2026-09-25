<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPeriod;
use App\Models\ActivityLog;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Services\InsuranceService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use ResolvesPeriod;

    public const PERIODS = [
        'jour' => 'Aujourd\'hui',
        'semaine' => 'Semaine',
        'mois' => 'Mois',
        'annee' => 'Année',
    ];

    public function __invoke(Request $request, InsuranceService $insurance): View
    {
        [$period, $from, $to] = $this->period($request);

        $open = Invoice::query()->invoices()->whereIn('status', Invoice::OPEN);

        return view('dashboard', [
            'insuranceAlert' => $insurance->message(),
            'insuranceLevel' => $insurance->level(),
            // Rappel hebdomadaire : télécharger une copie de la sauvegarde.
            'backupReminder' => ($last = app(Settings::class)->get('backups.last_download_at'))
                ? Carbon::parse($last)->lt(now()->subDays(7))
                : ActivityLog::query()->oldest('id')->value('created_at')?->lt(now()->subDays(7)) ?? false,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'kpis' => [
                'to_collect' => (int) (clone $open)->selectRaw('COALESCE(SUM(total_ttc - amount_paid), 0) as due')->value('due'),
                'pending_quotes' => Quote::query()->pending()->count(),
                'pending_amount' => (int) Quote::query()->pending()->sum('total_ttc'),
                'revenue' => $this->revenue($from, $to),
                'collected' => (int) Payment::query()->counted()->whereDate('paid_at', '>=', $from)->whereDate('paid_at', '<=', $to)->sum('amount'),
            ],
            'stats' => [
                'sent_quotes' => Quote::query()->whereNotNull('sent_at')->whereBetween('sent_at', [$from, $to->copy()->endOfDay()])->count(),
                'accepted_quotes' => Quote::query()->where('status', 'accepted')->whereBetween('accepted_at', [$from, $to->copy()->endOfDay()])->count(),
                'accepted_amount' => (int) Quote::query()->where('status', 'accepted')->whereBetween('accepted_at', [$from, $to->copy()->endOfDay()])->sum('total_ttc'),
                'refused_quotes' => Quote::query()->where('status', 'refused')->whereBetween('refused_at', [$from, $to->copy()->endOfDay()])->count(),
                'paid_invoices' => Invoice::query()->invoices()->where('status', 'paid')->whereDate('paid_at', '>=', $from)->whereDate('paid_at', '<=', $to)->count(),
                'unpaid_invoices' => (clone $open)->count(),
                'overdue_invoices' => Invoice::query()->overdue()->count(),
                'year_revenue' => $this->revenue(today()->startOfYear(), today()),
            ],
            'overdue' => Invoice::query()->overdue()->with('client')->orderBy('due_date')->limit(5)->get(),
            'toFollowUp' => Quote::query()->where('status', 'sent')->where('sent_at', '<=', now()->subDays(7))->with('client')->orderBy('sent_at')->limit(5)->get(),
            'newRequests' => QuoteRequest::query()->where('status', 'new')->whereHas('client')->count(),
            'latestRequests' => QuoteRequest::query()->where('status', 'new')->whereHas('client')->with(['client', 'worksite'])->latest('id')->limit(5)->get(),
            'nextInterventions' => Intervention::query()->active()->where('status', 'planned')
                ->whereDate('ends_on', '>=', today())->visible()->with(['client', 'worksite'])
                ->orderBy('starts_on')->orderBy('start_time')->limit(5)->get(),
            'lastPayments' => Payment::query()->counted()->with(['client', 'invoice'])->latest('paid_at')->latest('id')->limit(5)->get(),
        ]);
    }

    /**
     * Chiffre d'affaires HT facturé sur la période. Une facture supprimée
     * (annulée par avoir) ne compte pas du tout, pas plus que son avoir.
     */
    private function revenue(Carbon $from, Carbon $to): int
    {
        $invoiced = (int) Invoice::query()->invoices()->issued()->where('status', '!=', 'cancelled')
            ->whereDate('issue_date', '>=', $from)->whereDate('issue_date', '<=', $to)->sum('total_ht');
        // Avoirs isolés (sans facture annulée associée) : déduits.
        $credited = (int) Invoice::query()->credits()->issued()->whereNull('cancels_id')
            ->whereDate('issue_date', '>=', $from)->whereDate('issue_date', '<=', $to)->sum('total_ht');

        return $invoiced - $credited;
    }
}
