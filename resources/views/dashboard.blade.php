@extends('layouts.app', ['title' => 'Accueil'])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Bonjour {{ explode(' ', auth()->user()->name)[0] }}</h1>
            <p>{{ ucfirst(now()->locale('fr')->isoFormat('dddd D MMMM YYYY')) }}</p>
        </div>
        <div class="chips" role="group" aria-label="Période">
            @foreach (['Aujourd\'hui', 'Semaine', 'Mois', 'Année', 'Période…'] as $i => $period)
                <button type="button" class="chip {{ $i === 2 ? 'is-active' : '' }}" disabled>{{ $period }}</button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-3">
        <div class="card kpi kpi-accent">
            <span class="label">Montant à encaisser</span>
            <span class="value">{{ Money::format($kpis['to_collect']) }}</span>
        </div>
        <a class="card kpi kpi-accent kpi-link" href="{{ route('quotes.index', ['status' => 'sent']) }}">
            <span class="label">Devis en attente de réponse</span>
            <span class="value">{{ $kpis['pending_quotes'] }}</span>
            @if ($kpis['pending_amount'])<span class="muted small">{{ Money::format($kpis['pending_amount']) }} au total</span>@endif
        </a>
        <div class="card kpi kpi-accent">
            <span class="label">CA facturé du mois</span>
            <span class="value">{{ Money::format($kpis['month_revenue']) }}</span>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top:1rem">
        <div class="card">
            <div class="card-head"><h2>Activité</h2></div>
            <ul class="stat-list">
                <li><span>Devis acceptés cette année</span><strong>{{ $stats['accepted_quotes'] }}</strong></li>
                <li><span>Devis refusés cette année</span><strong>{{ $stats['refused_quotes'] }}</strong></li>
                <li><span>Factures payées</span><strong>{{ $stats['paid_invoices'] }}</strong></li>
                <li><span>Factures impayées</span><strong>{{ $stats['unpaid_invoices'] }}</strong></li>
                <li><span>CA de l'année</span><strong>{{ Money::format($stats['year_revenue']) }}</strong></li>
            </ul>
        </div>
        <div class="card">
            <div class="card-head"><h2>Pour commencer</h2></div>
            <ul class="stat-list">
                <li><a href="{{ route('settings.company') }}">Vérifier les informations de l'entreprise</a></li>
                <li><a href="{{ route('settings.branding') }}">Ajouter le logo</a></li>
                <li><a href="{{ route('settings.vat') }}">Choisir le régime de TVA</a></li>
                <li><a href="{{ route('settings.numbering') }}">Vérifier la numérotation</a></li>
            </ul>
        </div>
    </div>
@endsection
