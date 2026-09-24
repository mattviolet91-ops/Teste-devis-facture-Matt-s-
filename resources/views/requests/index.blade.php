@extends('layouts.app', ['title' => 'Demandes de devis'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Demandes de devis</h1>
            <p>Reçues depuis votre site internet.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>À traiter</h2></div>
        @if ($new->isEmpty())
            <p class="muted" style="margin:0">Aucune nouvelle demande.</p>
        @else
            <ul class="stat-list">
                @foreach ($new as $item)
                    <li>
                        <a href="{{ route('requests.show', $item) }}"><strong>{{ $item->client->displayName() }}</strong>
                            <span class="muted small">· {{ $item->worksLabel() ?: 'voir le message' }}</span></a>
                        <span class="small muted">{{ $item->created_at->format('d/m H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="card">
        <h2>Lien du formulaire</h2>
        <p class="small muted">À mettre sur votre site internet (bouton « Demander un devis »), sur Google, Facebook ou dans vos SMS.</p>
        <div class="copy-field">
            <input type="text" value="{{ $formUrl }}" readonly aria-label="Lien du formulaire" data-copy-source>
            <button class="btn btn-secondary btn-sm" type="button" data-copy>Copier</button>
            <a class="btn btn-secondary btn-sm" href="{{ $formUrl }}" target="_blank" rel="noopener">Ouvrir</a>
        </div>
    </div>

    @if ($handled->isNotEmpty())
        <div class="card">
            <div class="card-head"><h2>Traitées</h2></div>
            <ul class="stat-list">
                @foreach ($handled as $item)
                    <li><a href="{{ route('requests.show', $item) }}">{{ $item->client->displayName() }}</a><span class="small muted">{{ $item->created_at->format('d/m/Y') }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
