@extends('layouts.app', ['title' => 'Avis Google · '.$review->client->displayName()])

@php
    $client = $review->client;
    $phone = preg_replace('/\D/', '', (string) $client->phone);
    $whatsappPhone = preg_match('/^0\d{9}$/', $phone) ? '33'.substr($phone, 1) : $phone;
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Demander un avis</h1>
            <p><a href="{{ route('clients.show', $client) }}">{{ $client->displayName() }}</a>
                @if ($review->status === 'sent') · <span class="badge">déjà envoyé le {{ $review->sent_at->format('d/m/Y') }} ({{ $review->channelLabel() }})</span>@endif</p>
        </div>
    </div>
    @error('email')<div class="alert alert-error">{{ $message }}</div>@enderror

    <div class="card" data-share data-track="{{ route('reviews.track', $review) }}">
        @csrf
        <h2>Message</h2>
        <p class="muted small">Modifiable avant l'envoi (modèle dans <a href="{{ route('settings.emails') }}#avis">Réglages → Emails</a>).</p>
        <div class="field">
            <label for="review-message">Message</label>
            <textarea id="review-message" rows="7" data-share-message>{{ $message }}</textarea>
        </div>
        <div class="share-buttons">
            <a class="btn" href="#" data-share-to="whatsapp" data-phone="{{ $whatsappPhone }}" @if (! $phone) aria-disabled="true" @endif><x-icon name="message" /> WhatsApp</a>
            <a class="btn" href="#" data-share-to="sms" data-phone="{{ $phone }}"><x-icon name="message" /> SMS</a>
            <button class="btn btn-secondary" type="button" data-share-to="copy"><x-icon name="copy" /> Copier</button>
        </div>
        @if ($client->email)
            <form method="POST" action="{{ route('reviews.email', $review) }}" style="margin-top:.75rem">
                @csrf
                <button class="btn btn-secondary" type="submit"><x-icon name="mail" /> Envoyer par email à {{ $client->email }}</button>
            </form>
        @endif
    </div>

    @if ($review->status === 'pending')
        <form method="POST" action="{{ route('reviews.skip', $review) }}">
            @csrf
            <button class="btn btn-secondary" type="submit">Ne pas demander d'avis à ce client</button>
        </form>
    @endif
@endsection
