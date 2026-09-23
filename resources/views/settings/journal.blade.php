@extends('settings.layout', ['title' => 'Journal d\'activité'])

@section('settings')
    <form method="GET" action="{{ route('settings.journal') }}" class="card filters">
        <label class="search-field" for="q">
            <x-icon name="search" /><span class="visually-hidden">Rechercher</span>
            <input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="Rechercher : devis, facture, connexion…" autocomplete="off">
        </label>
        <div class="chips" style="margin-top:.5rem">
            <a class="chip {{ ! request()->boolean('connexions') ? 'is-active' : '' }}" href="{{ route('settings.journal') }}">Tout</a>
            <a class="chip {{ request()->boolean('connexions') ? 'is-active' : '' }}" href="{{ route('settings.journal', ['connexions' => 1]) }}">Connexions</a>
        </div>
    </form>

    <div class="card">
        <p class="muted small">Toutes les actions importantes sont enregistrées : création, envoi, paiement, suppression, connexion… avec la date et l'adresse IP.</p>
        <ol class="timeline">
            @forelse ($logs as $log)
                <li>
                    <span class="muted small">{{ $log->created_at->format('d/m/Y H:i') }}</span>
                    <span>{{ $log->description }}@if ($log->ip_address)<br><span class="muted small">IP {{ $log->ip_address }}</span>@endif</span>
                </li>
            @empty
                <li><span></span><span class="muted">Aucune activité.</span></li>
            @endforelse
        </ol>
    </div>
    {{ $logs->links('components.pagination') }}
@endsection
