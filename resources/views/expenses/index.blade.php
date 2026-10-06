@extends('layouts.app', ['title' => 'Frais par chantier'])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Frais par chantier</h1>
            <p>Chaque chantier facturé, entièrement ou en partie, avec ses frais et ce qu'il vous reste. Visible par vous seul.</p>
        </div>
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
            <p>{{ $q ? 'Aucun chantier facturé pour « '.$q.' ».' : 'Aucun chantier facturé pour le moment. Dès qu\'une facture est envoyée, son chantier apparaît ici.' }}</p>
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
                            <strong class="amount {{ $job['remaining'] < 0 ? 'text-danger' : '' }}">{{ Money::format($job['remaining']) }}</strong>
                            @if ($job['fully'])
                                <span class="badge badge-success">Facturé entièrement</span>
                            @else
                                <span class="badge badge-warning">Facturé en partie · {{ $job['percent'] }} %</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
