@extends('layouts.app', ['title' => 'Frais par chantier'])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Frais par chantier</h1>
            <p>Chaque chantier, dès que son devis est accepté, avec ses frais et ce qu'il vous reste. Visible par vous seul.</p>
        </div>
        <a class="btn" href="{{ route('expenses.create') }}"><x-icon name="plus" /> Nouveau frais</a>
    </div>

    <form method="GET" action="{{ route('expenses.index') }}" class="card" role="search">
        <label class="search-field" for="frais-q">
            <x-icon name="search" />
            <span class="visually-hidden">Rechercher un chantier</span>
            <input id="frais-q" type="search" name="q" value="{{ $q }}" placeholder="Client, n° de devis ou de facture, objet…" autocomplete="off">
        </label>
    </form>

    @if ($jobs->isEmpty())
        <div class="card empty-state">
            <p>{{ $q ? 'Aucun chantier pour « '.$q.' ».' : 'Aucun chantier pour le moment. Dès qu\'un devis est accepté, son chantier apparaît ici.' }}</p>
        </div>
    @else
        <div class="card">
            <ul class="stat-list">
                <li><span>Facturé{{ $q ? '' : ' (tous chantiers)' }}</span><strong>{{ Money::format($totals['invoiced']) }}</strong></li>
                <li><span>Frais</span><strong>− {{ Money::format($totals['expenses']) }}</strong></li>
                <li class="remaining"><span>Il vous reste</span><strong class="{{ $totals['remaining'] < 0 ? 'text-danger' : '' }}">{{ Money::format($totals['remaining']) }}</strong></li>
            </ul>
        </div>

        <ul class="list" style="margin-top:1rem">
            @foreach ($jobs as $job)
                <li>
                    <a class="list-item" href="{{ $job['url'] }}">
                        <span class="list-main">
                            <strong>{{ $job['client']?->displayName() }} · {{ $job['title'] }}</strong>
                            <span class="muted small">{{ $job['quote'] ? 'Devis '.$job['quote']->number.' · ' : '' }}{{ $job['numbers'] }}{{ $job['address'] ? ' · '.$job['address'] : '' }}</span>
                            <span class="small">{{ $job['expenses_count'] }} frais · {{ Money::format($job['expenses_total']) }}</span>
                        </span>
                        <span class="list-meta">
                            @if ($job['billed'])
                                <strong class="amount {{ $job['remaining'] < 0 ? 'text-danger' : '' }}">{{ Money::format($job['remaining']) }}</strong>
                            @else
                                <strong class="amount muted {{ $job['expected'] < 0 ? 'text-danger' : '' }}">{{ Money::format($job['expected']) }} <span class="small">prévu</span></strong>
                            @endif
                            @if ($job['fully'])
                                <span class="badge badge-success">Facturé entièrement</span>
                            @elseif ($job['billed'])
                                <span class="badge badge-warning">Facturé en partie · {{ $job['percent'] }} %</span>
                            @else
                                <span class="badge badge-info">En cours · pas encore facturé</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @unless ($q)
        <a class="card list-item" href="{{ route('expenses.general') }}" style="margin-top:1rem">
            <span class="list-main"><strong>Frais généraux</strong><span class="muted small">Sans chantier : outillage, carburant, assurance du véhicule…</span></span>
            <span class="list-meta"><strong class="amount">{{ Money::format($general['expenses_total']) }}</strong><span class="small muted">{{ $general['expenses']->count() }} frais</span></span>
        </a>
    @endunless
@endsection
