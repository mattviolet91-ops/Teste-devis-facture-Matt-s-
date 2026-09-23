@extends('layouts.app', ['title' => 'Emails'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Emails envoyés</h1>
            <p>Pour écrire un email, ouvrez un devis, une facture ou une fiche client.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('emails.index') }}" class="card filters">
        <label class="search-field" for="q">
            <x-icon name="search" /><span class="visually-hidden">Rechercher</span>
            <input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="Destinataire ou objet…" autocomplete="off">
        </label>
    </form>

    @if ($emails->isEmpty())
        <div class="card empty"><x-icon name="mail" /><h2>Aucun email envoyé</h2></div>
    @else
        <ul class="list">
            @foreach ($emails as $email)
                <li>
                    <a class="list-item" href="{{ route('emails.show', $email) }}">
                        <span class="list-main">
                            <strong>{{ $email->subject }}</strong>
                            <span class="muted small">{{ $email->to }} · {{ $email->created_at->format('d/m/Y H:i') }}{{ $email->attachment ? ' · PDF joint' : '' }}</span>
                        </span>
                        <span class="list-meta">
                            <span class="badge {{ $email->isSent() ? 'badge-success' : 'badge-danger' }}">{{ $email->isSent() ? 'Envoyé' : 'Échec' }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        {{ $emails->links('components.pagination') }}
    @endif
@endsection
