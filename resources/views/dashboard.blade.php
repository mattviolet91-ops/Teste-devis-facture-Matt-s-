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

    @if ($newRequests && ! in_array('requests', \App\Support\Navigation::home(), true))
        <a class="alert alert-info kpi-link" href="{{ route('requests.index') }}" style="display:block"><strong>{{ $newRequests }} nouvelle{{ $newRequests > 1 ? 's' : '' }} demande{{ $newRequests > 1 ? 's' : '' }} de devis</strong> reçue{{ $newRequests > 1 ? 's' : '' }} depuis votre site. Voir →</a>
    @endif

    @if ($backupReminder)
        <div class="alert alert-info" role="status">Pensez à télécharger une copie de votre sauvegarde (une fois par semaine). <a href="{{ route('settings.backups') }}">Télécharger ma sauvegarde</a></div>
    @endif

    @php $periodShown = false; @endphp
    <div class="home-blocks">
    @foreach (\App\Support\Navigation::home() as $block)
        @if (in_array($block, ['kpis', 'activity'], true) && ! $periodShown)
            @php $periodShown = true; @endphp
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
        @endif
        @switch($block)
            @case('kpis')
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
                @break
            @case('activity')
            <div class="card">
                <div class="card-head"><h2>Activité {{ $periodLabel }}</h2><a class="small" href="{{ route('statistics') }}">Provenance</a></div>
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
                @break
            @case('todo')
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
                                <span><strong>{{ Money::format($invoice->balance()) }}</strong> <a class="small" href="{{ route('reminders.show', $invoice) }}">Relancer</a></span>
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
                @break
            @case('planning')
            @if ($nextInterventions->isNotEmpty())
                <div class="card">
                    <div class="card-head"><h2>Prochains rendez-vous et chantiers</h2><a class="small" href="{{ route('planning.index') }}">Planning</a></div>
                    <ul class="stat-list">
                        @foreach ($nextInterventions as $item)
                            <li>
                                <a href="{{ route('planning.show', $item) }}">{{ $item->isAppointment() ? 'RDV · ' : '' }}{{ $item->heading() }} <span class="muted small">· {{ $item->title }}</span></a>
                                <span class="small">{{ $item->starts_on->isToday() ? 'aujourd\'hui' : ($item->starts_on->isTomorrow() ? 'demain' : $item->starts_on->locale('fr')->isoFormat('ddd D MMM')) }}{{ $item->start_time ? ' · '.$item->timeLabel() : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
                @break
            @case('requests')
                <div class="card">
                    <div class="card-head"><h2>Demandes de devis{{ $newRequests ? ' ('.$newRequests.')' : '' }}</h2><a class="small" href="{{ route('requests.index') }}">Tout voir</a></div>
                    @if ($latestRequests->isEmpty())
                        <p class="muted" style="margin:0">Aucune nouvelle demande.</p>
                    @else
                        <ul class="stat-list">
                            @foreach ($latestRequests as $item)
                                @php $city = $item->worksite?->city ?? $item->client->city; @endphp
                                <li>
                                    <a href="{{ route('requests.show', $item) }}"><strong>{{ $item->client->displayName() }}</strong>
                                        <span class="muted small">· {{ $item->worksLabel() ?: 'voir le message' }}{{ $city ? ' · '.$city : '' }}</span></a>
                                    <span class="small muted">{{ $item->created_at->isToday() ? $item->created_at->format('H:i') : $item->created_at->format('d/m') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @break
            @case('shortcuts')
            <div class="card">
                <div class="card-head"><h2>Raccourcis</h2></div>
                <div class="quick-actions" style="margin:0">
                    <a class="quick-action" href="{{ route('quotes.create') }}"><x-icon name="file" /> Devis</a>
                    <a class="quick-action" href="{{ route('planning.create', ['type' => 'rdv']) }}"><x-icon name="calendar" /> Rendez-vous</a>
                    <a class="quick-action" href="{{ route('clients.create') }}"><x-icon name="users" /> Client</a>
                    <a class="quick-action" href="{{ route('invoices.create') }}"><x-icon name="receipt" /> Facture</a>
                </div>
            </div>
                @break
            @case('payments')
            @if ($lastPayments->isNotEmpty())
                <div class="card">
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
                @break
        @endswitch
    @endforeach
    </div>

    <p class="small muted" style="margin-top:1rem;text-align:center"><a href="{{ route('settings.display') }}">Personnaliser l'accueil et la barre du bas</a></p>
@endsection
