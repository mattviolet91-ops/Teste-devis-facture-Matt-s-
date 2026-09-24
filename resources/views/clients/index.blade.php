@extends('layouts.app', ['title' => 'Clients'])

@section('content')
    <div class="page-head">
        <h1>Clients</h1>
        <div class="action-bar" style="margin:0">
            <a class="btn btn-secondary" href="{{ route('clients.import') }}">Importer</a>
            <a class="btn" href="{{ route('clients.create') }}"><x-icon name="plus" /> Nouveau client</a>
        </div>
    </div>

    <form method="GET" action="{{ route('clients.index') }}" class="card filters">
        <label class="search-field" for="q">
            <x-icon name="search" />
            <span class="visually-hidden">Rechercher un client</span>
            <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nom, téléphone, email, ville, adresse de chantier…" autocomplete="off">
        </label>
        <div class="filter-row">
            <select name="type" aria-label="Type de client">
                <option value="">Tous les types</option>
                @foreach (\App\Models\Client::TYPES as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['type'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="status" aria-label="Statut">
                <option value="">Prospects et clients</option>
                @foreach (\App\Models\Client::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}s</option>
                @endforeach
            </select>
            <button class="btn btn-secondary" type="submit">Filtrer</button>
        </div>
        <div class="chips" role="group" aria-label="Tri">
            @foreach (['recent' => 'Récents', 'az' => 'A → Z'] as $key => $label)
                <a class="chip {{ $filters['sort'] === $key ? 'is-active' : '' }}" href="{{ route('clients.index', array_filter(['sort' => $key] + $filters)) }}">{{ $label }}</a>
            @endforeach
        </div>
    </form>

    @if ($clients->isEmpty())
        <div class="card empty">
            <x-icon name="users" />
            @if (array_filter($filters))
                <h2>Aucun résultat</h2>
                <p><a href="{{ route('clients.index') }}">Voir tous les clients</a></p>
            @else
                <h2>Aucun client pour l'instant</h2>
                <p><a class="btn" href="{{ route('clients.create') }}">Créer le premier client</a></p>
            @endif
        </div>
    @else
        <p class="muted small">{{ $clients->total() }} {{ $clients->total() > 1 ? 'fiches' : 'fiche' }}</p>
        <ul class="list">
            @foreach ($clients as $client)
                <li>
                    <a class="list-item" href="{{ route('clients.show', $client) }}">
                        <span class="avatar">{{ $client->initials() }}</span>
                        <span class="list-main">
                            <strong>{{ $client->displayName() }}</strong>
                            <span class="muted small">
                                {{ collect([$client->city, $client->phone])->filter()->implode(' · ') ?: \App\Models\Client::TYPES[$client->type] }}
                            </span>
                        </span>
                        <span class="list-meta">
                            <span class="badge {{ $client->status === 'client' ? 'badge-success' : '' }}">{{ \App\Models\Client::STATUSES[$client->status] }}</span>
                            @if ($client->worksites_count > 1)
                                <span class="muted small">{{ $client->worksites_count }} chantiers</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        {{ $clients->links('components.pagination') }}
    @endif
@endsection
