@extends('layouts.app', ['title' => 'Statistiques'])

@php
    use App\Support\Money;
    $periodLabel = match ($period) {
        'mois' => 'ce mois-ci',
        'tout' => 'depuis le début',
        'perso' => 'du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y'),
        default => 'cette année',
    };
    $maxRevenue = max(1, (int) $rows->max('revenue'), (int) $rows->max('accepted_amount'));
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Provenance des clients</h1>
            <p>D'où viennent vos clients et ce qu'ils vous rapportent, {{ $periodLabel }}.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('statistics') }}" class="period-bar">
        <div class="chips" role="group" aria-label="Période">
            @foreach (\App\Http\Controllers\StatisticsController::PERIODS as $key => $label)
                <a class="chip {{ $period === $key ? 'is-active' : '' }}" href="{{ route('statistics', ['periode' => $key]) }}">{{ $label }}</a>
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

    <div class="grid grid-3">
        <div class="card kpi kpi-accent"><span class="label">Nouveaux clients</span><span class="value">{{ $totals['prospects'] }}</span></div>
        <div class="card kpi kpi-accent"><span class="label">Devis acceptés</span><span class="value">{{ $totals['accepted'] }}</span><span class="muted small">sur {{ $totals['sent'] }} envoyé(s) · {{ Money::format($totals['accepted_amount']) }} TTC</span></div>
        <div class="card kpi kpi-accent"><span class="label">CA facturé (HT)</span><span class="value">{{ Money::format($totals['revenue']) }}</span></div>
    </div>

    @if ($rows->isEmpty())
        <div class="card" style="margin-top:1rem"><p class="muted" style="margin:0">Aucune donnée sur cette période. Renseignez « Comment nous a-t-il connu ? » sur la fiche de chaque client.</p></div>
    @else
        <div class="grid grid-2" style="margin-top:1rem">
            @foreach ($rows as $key => $row)
                <div class="card">
                    <div class="card-head"><h2>{{ $row['label'] }}</h2>@if ($row['rate'] !== null)<span class="small muted">{{ $row['rate'] }} % acceptés</span>@endif</div>
                    <div class="progress" title="Part du chiffre d'affaires"><span style="width: {{ max(0, (int) round(max($row['revenue'], $row['accepted_amount']) * 100 / $maxRevenue)) }}%"></span></div>
                    <ul class="stat-list">
                        <li><span>Nouveaux clients</span><strong>{{ $row['prospects'] }}</strong></li>
                        <li><span>Devis envoyés</span><strong>{{ $row['sent'] }}</strong></li>
                        <li><span>Devis acceptés</span><strong>{{ $row['accepted'] }}{{ $row['accepted_amount'] ? ' · '.Money::format($row['accepted_amount']) : '' }}</strong></li>
                        <li><span>CA facturé (HT)</span><strong>{{ Money::format($row['revenue']) }}</strong></li>
                    </ul>
                    @if ($key !== 'inconnue')
                        <a class="small" href="{{ route('clients.index', ['source' => $key]) }}">Voir les clients</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endsection
