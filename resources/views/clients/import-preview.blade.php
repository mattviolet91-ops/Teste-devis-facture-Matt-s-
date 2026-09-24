@extends('layouts.app', ['title' => 'Aperçu de l\'import'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Aperçu de l'import</h1>
            <p><strong>{{ count($new) }}</strong> nouveau(x) client(s) · {{ count($duplicates) }} doublon(s) ignoré(s){{ $empty ? ' · '.$empty.' ligne(s) vide(s)' : '' }}</p>
        </div>
    </div>

    @if (count($new))
        <form method="POST" action="{{ route('clients.import.store') }}" class="card" data-confirm="Importer {{ count($new) }} client(s) ?">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <p class="small muted">Chaque client garde sa date de création Wix. Les messages, commentaires et libellés Wix sont copiés dans ses notes.
                Un chantier est créé quand l'adresse est complète.</p>
            <div class="form-actions"><button class="btn" type="submit">Importer {{ count($new) }} client(s)</button></div>
        </form>

        <div class="card">
            <h2>Nouveaux clients</h2>
            <ul class="stat-list">
                @foreach (array_slice($new, 0, 300) as $contact)
                    <li>
                        <span><strong>{{ trim($contact['first_name'].' '.$contact['last_name']) }}</strong><br>
                            <span class="muted small">{{ collect([$contact['phone'], $contact['email'], trim($contact['postal_code'].' '.$contact['city'])])->filter()->implode(' · ') }}</span></span>
                        @if ($contact['paid'])<span class="badge badge-success">Client</span>@endif
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <div class="card empty"><h2>Aucun nouveau client à importer</h2><p class="muted">Tous les contacts du fichier sont déjà présents.</p></div>
    @endif

    @if (count($duplicates))
        <details class="card">
            <summary><strong>Doublons ignorés ({{ count($duplicates) }})</strong></summary>
            <ul class="stat-list" style="margin-top:.75rem">
                @foreach ($duplicates as $contact)
                    <li><span>{{ trim($contact['first_name'].' '.$contact['last_name']) }} <span class="muted small">{{ $contact['phone'] ?? $contact['email'] }}</span></span>
                        <span class="small muted">déjà : {{ $contact['duplicate_of'] }}</span></li>
                @endforeach
            </ul>
        </details>
    @endif

    <p><a href="{{ route('clients.import') }}">Choisir un autre fichier</a></p>
@endsection
