@extends('layouts.app', ['title' => 'Entretiens'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Relances d'entretien</h1>
            <p>Clients à recontacter pour un nouvel entretien (démoussage, nettoyage…).</p>
        </div>
        <form method="POST" action="{{ route('maintenance.scan') }}">
            @csrf
            <button class="btn btn-secondary" type="submit">Analyser les factures</button>
        </form>
    </div>

    @unless ($withDelay)
        <div class="alert alert-info">Pour recevoir ces rappels, indiquez un délai d'entretien sur vos prestations (ex. démoussage : 3 ans) dans
            <a href="{{ route('catalog.index') }}">Prestations</a>, puis appuyez sur « Analyser les factures ».</div>
    @endunless

    <div class="chips" role="group" aria-label="Filtre" style="margin-bottom:1rem">
        @foreach (\App\Http\Controllers\MaintenanceController::TABS as $key => $label)
            <a class="chip {{ $tab === $key ? 'is-active' : '' }}" href="{{ route('maintenance.index', ['onglet' => $key]) }}">{{ $label }}{{ isset($counts[$key]) ? ' ('.$counts[$key].')' : '' }}</a>
        @endforeach
    </div>

    @if ($reminders->isEmpty())
        <div class="card empty"><x-icon name="check" /><h2>{{ $tab === 'a-relancer' ? 'Aucun entretien à proposer pour le moment' : 'Rien ici' }}</h2></div>
    @else
        <ul class="list">
            @foreach ($reminders as $reminder)
                @php $late = (int) $reminder->due_on->diffInDays(today(), false); @endphp
                <li>
                    <a class="list-item" href="{{ route('maintenance.show', $reminder) }}">
                        <span class="list-main">
                            <strong>{{ $reminder->client->displayName() }}</strong>
                            <span class="muted small">{{ $reminder->label }} · fait en {{ $reminder->done_on->locale('fr')->isoFormat('MMMM YYYY') }}{{ $reminder->worksite ? ' · '.$reminder->worksite->city : '' }}{{ $reminder->contact_count ? ' · relancé '.$reminder->contact_count.' fois' : '' }}</span>
                        </span>
                        <span class="list-meta">
                            @if (in_array($reminder->status, ['done', 'dismissed'], true))
                                <span class="badge">{{ $reminder->statusLabel() }}</span>
                            @else
                                <span class="badge {{ $late >= 0 ? 'badge-warning' : 'badge-info' }}">{{ $late >= 0 ? 'depuis le '.$reminder->due_on->format('d/m/Y') : 'le '.$reminder->due_on->format('d/m/Y') }}</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        {{ $reminders->links('components.pagination') }}
    @endif
@endsection
