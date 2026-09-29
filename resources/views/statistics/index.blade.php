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
    @include('statistics._tabs')
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

    @if ($found->isNotEmpty())
        @php $maxFound = max(1, $found->max('count')); $totalFound = $found->sum('count'); @endphp
        <div class="card" style="margin-top:1rem">
            <div class="card-head"><h2>Comment vos clients vous ont trouvés</h2><span class="small muted">{{ $totalFound }} nouveau{{ $totalFound > 1 ? 'x' : '' }} client{{ $totalFound > 1 ? 's' : '' }}</span></div>
            <ul class="hbars" aria-label="Nouveaux clients par provenance">
                @foreach ($found as $key => $item)
                    @php $percent = (int) round($item['count'] * 100 / $totalFound); @endphp
                    <li class="{{ $key === 'inconnue' ? 'is-unknown' : '' }}" title="{{ $item['label'] }} : {{ $item['count'] }} client{{ $item['count'] > 1 ? 's' : '' }} ({{ $percent }} %)">
                        <span class="hbar-label">{{ $item['label'] }}</span>
                        <span class="hbar-track" aria-hidden="true"><span class="hbar-fill" style="width: {{ max(1, (int) round($item['count'] * 100 / $maxFound)) }}%"></span></span>
                        <span class="hbar-value">{{ $item['count'] }} · {{ $percent }} %</span>
                    </li>
                @endforeach
            </ul>
            @if ($found->has('inconnue'))
                <p class="muted small" style="margin:.75rem 0 0">« Non renseignée » : complétez la provenance sur la fiche du client ou depuis son rendez-vous.</p>
            @endif
        </div>
    @endif

    @if ($team->isNotEmpty())
        <div class="card" style="margin-top:1rem">
            <div class="card-head"><h2>Par compte</h2><span class="small muted">{{ $periodLabel }}</span></div>
            <div class="table-wrap">
                <table class="table team-table">
                    <thead>
                        <tr><th>Compte</th><th class="num">Clients</th><th class="num">Rendez-vous</th><th class="num">Devis faits</th><th class="num">Envoyés</th><th class="num">Signés</th><th class="num">Montant signé</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($team as $member)
                            <tr>
                                <td><strong>{{ $member['name'] }}</strong><br><span class="small muted">{{ $member['role'] }}{{ $member['disabled'] ? ' · désactivé' : '' }}</span></td>
                                <td class="num">{{ $member['clients'] }}</td>
                                <td class="num">{{ $member['appointments'] }}</td>
                                <td class="num">{{ $member['quotes'] }}</td>
                                <td class="num">{{ $member['sent'] }}</td>
                                <td class="num">{{ $member['signed'] }}@if ($member['rate'] !== null)<br><span class="small muted">{{ $member['rate'] }} %</span>@endif</td>
                                <td class="num">{{ Money::format($member['signed_amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted small" style="margin:.75rem 0 0">Compté pour le compte qui a créé le client, le rendez-vous ou le devis (depuis l'arrivée de ce suivi). Taux = devis signés ÷ devis envoyés.</p>
        </div>
    @endif

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
