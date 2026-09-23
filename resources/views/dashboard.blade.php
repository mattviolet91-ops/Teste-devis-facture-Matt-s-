@extends('layouts.app', ['title' => 'Accueil'])

@php
    use App\Support\Money;
    $periodLabel = match ($period) {
        'jour' => 'aujourd\'hui',
        'semaine' => 'cette semaine',
        'annee' => 'cette année',
        'perso' => 'du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y'),
        default => 'ce mois-ci',
    };
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Bonjour {{ explode(' ', auth()->user()->name)[0] }}</h1>
            <p>{{ ucfirst(now()->locale('fr')->isoFormat('dddd D MMMM YYYY')) }}</p>
        </div>
    </div>

    @if ($insuranceAlert)
        <div class="alert {{ $insuranceLevel === 'danger' ? 'alert-error' : 'alert-warning' }}" role="alert">
            {{ $insuranceAlert }} <a href="{{ route('settings.insurance') }}">Mettre à jour l'assurance</a>
        </div>
    @endif

    <div class="grid grid-3">
        <a class="card kpi kpi-accent kpi-link" href="{{ route('invoices.index', ['status' => 'unpaid']) }}">
            <span class="label">Montant à encaisser</span>
            <span class="value">{{ Money::format($kpis['to_collect']) }}</span>
            @if ($stats['overdue_invoices'])<span class="small" style="color:var(--danger, #c0392b)">{{ $stats['overdue_invoices'] }} facture(s) en retard</span>@endif
        </a>
        <a class="card kpi kpi-accent kpi-link" href="{{ route('quotes.index', ['status' => 'sent']) }}">
            <span class="label">Devis en attente de réponse</span>
            <span class="value">{{ $kpis['pending_quotes'] }}</span>
            @if ($kpis['pending_amount'])<span class="muted small">{{ Money::format($kpis['pending_amount']) }} au total</span>@endif
        </a>
        <a class="card kpi kpi-accent kpi-link" href="{{ route('payments.index') }}">
            <span class="label">Encaissé {{ $periodLabel }}</span>
            <span class="value">{{ Money::format($kpis['collected']) }}</span>
        </a>
    </div>

    <form method="GET" action="{{ route('dashboard') }}" class="period-bar">
        <div class="chips" role="group" aria-label="Période">
            @foreach (\App\Http\Controllers\DashboardController::PERIODS as $key => $label)
                <a class="chip {{ $period === $key ? 'is-active' : '' }}" href="{{ route('dashboard', ['periode' => $key]) }}">{{ $label }}</a>
            @endforeach
            <details class="period-custom" @if ($period === 'perso') open @endif>
                <summary class="chip {{ $period === 'perso' ? 'is-active' : '' }}">Période…</summary>
                <input type="hidden" name="periode" value="perso">
                <input type="date" name="du" value="{{ $from->toDateString() }}" aria-label="Du">
                <input type="date" name="au" value="{{ $to->toDateString() }}" aria-label="Au">
                <button class="btn btn-sm" type="submit">OK</button>
            </details>
        </div>
    </form>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>Activité {{ $periodLabel }}</h2></div>
            <ul class="stat-list">
                <li><span>CA facturé (HT)</span><strong>{{ Money::format($kpis['revenue']) }}</strong></li>
                <li><span>Encaissé</span><strong>{{ Money::format($kpis['collected']) }}</strong></li>
                <li><span>Devis envoyés</span><strong>{{ $stats['sent_quotes'] }}</strong></li>
                <li><span>Devis acceptés</span><strong>{{ $stats['accepted_quotes'] }}{{ $stats['accepted_amount'] ? ' · '.Money::format($stats['accepted_amount']) : '' }}</strong></li>
                <li><span>Devis refusés</span><strong>{{ $stats['refused_quotes'] }}</strong></li>
                <li><span>Factures payées</span><strong>{{ $stats['paid_invoices'] }}</strong></li>
                <li><span>Factures à encaisser (toutes)</span><strong>{{ $stats['unpaid_invoices'] }}</strong></li>
                <li><span>CA de l'année (HT)</span><strong>{{ Money::format($stats['year_revenue']) }}</strong></li>
            </ul>
        </div>

        <div class="card">
            <div class="card-head"><h2>À faire</h2></div>
            @if ($overdue->isEmpty() && $toFollowUp->isEmpty())
                <p class="muted" style="margin:0">Rien d'urgent. 👍</p>
            @endif
            @if ($overdue->isNotEmpty())
                <h3 class="small muted">Factures en retard</h3>
                <ul class="stat-list">
                    @foreach ($overdue as $invoice)
                        <li>
                            <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }} · {{ $invoice->client?->displayName() }}</a>
                            <span><strong>{{ Money::format($invoice->balance()) }}</strong> <a class="small" href="{{ route('emails.create', ['facture' => $invoice->id, 'relance' => 1]) }}">Relancer</a></span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($toFollowUp->isNotEmpty())
                <h3 class="small muted">Devis sans réponse depuis plus de 7 jours</h3>
                <ul class="stat-list">
                    @foreach ($toFollowUp as $quote)
                        <li>
                            <a href="{{ route('quotes.show', $quote) }}">{{ $quote->number }} · {{ $quote->client?->displayName() }}</a>
                            <span class="small">{{ $quote->viewed_at ? 'consulté' : 'pas ouvert' }} · <a href="{{ route('emails.create', ['devis' => $quote->id, 'relance' => 1]) }}">Relancer</a></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($lastPayments->isNotEmpty())
        <div class="card" style="margin-top:1rem">
            <div class="card-head"><h2>Derniers paiements</h2><a class="small" href="{{ route('payments.index') }}">Tout voir</a></div>
            <ul class="stat-list">
                @foreach ($lastPayments as $payment)
                    <li>
                        <span>{{ $payment->client?->displayName() }} <span class="muted small">· {{ $payment->paid_at->format('d/m/Y') }} · {{ $payment->methodLabel() }}</span></span>
                        <strong>{{ Money::format($payment->amount) }}</strong>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
