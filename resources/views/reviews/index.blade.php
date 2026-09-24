@extends('layouts.app', ['title' => 'Avis Google'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Avis Google</h1>
            <p>Après chaque chantier payé, un email demande un avis au client. Sans email, vous le faites ici par SMS ou WhatsApp.</p>
        </div>
    </div>

    @unless ($enabled)
        <div class="alert alert-info">Demande automatique désactivée. Collez votre lien d'avis Google et activez-la dans <a href="{{ route('settings.emails') }}#avis">Réglages → Emails</a>.</div>
    @endunless

    <div class="card">
        <div class="card-head"><h2>À demander</h2></div>
        @if ($pending->isEmpty())
            <p class="muted" style="margin:0">Rien à faire pour le moment. 👍</p>
        @else
            <ul class="stat-list">
                @foreach ($pending as $review)
                    <li>
                        <a href="{{ route('reviews.show', $review) }}">{{ $review->client->displayName() }}</a>
                        <span class="small muted">{{ $review->invoice?->number }} · payé le {{ $review->invoice?->paid_at?->format('d/m/Y') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($sent->isNotEmpty())
        <div class="card">
            <div class="card-head"><h2>Demandes envoyées</h2></div>
            <ul class="stat-list">
                @foreach ($sent as $review)
                    <li><span>{{ $review->client->displayName() }}</span><span class="small muted">{{ $review->sent_at->format('d/m/Y') }} · {{ $review->channelLabel() }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
