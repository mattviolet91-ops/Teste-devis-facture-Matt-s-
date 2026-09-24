@extends('layouts.app', ['title' => 'Entretien '.$reminder->client->displayName()])

@php
    $phone = preg_replace('/\D/', '', (string) $reminder->client->phone);
    $whatsappPhone = preg_match('/^0\d{9}$/', $phone) ? '33'.substr($phone, 1) : $phone;
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Proposer un entretien</h1>
            <p><a href="{{ route('clients.show', $reminder->client) }}">{{ $reminder->client->displayName() }}</a> · {{ $reminder->statusLabel() }}</p>
        </div>
    </div>

    <div class="card">
        <ul class="stat-list">
            <li><span>Prestation</span><strong>{{ $reminder->label }}</strong></li>
            <li><span>Réalisée</span><strong>{{ $reminder->done_on->format('d/m/Y') }} (il y a {{ $reminder->age() }})</strong></li>
            @if ($reminder->worksite)<li><span>Chantier</span><strong>{{ $reminder->worksite->fullAddress() }}</strong></li>@endif
            @if ($reminder->invoice)<li><span>Facture</span><a href="{{ route('invoices.show', $reminder->invoice) }}">{{ $reminder->invoice->number }}</a></li>@endif
            <li><span>Relances faites</span><strong>{{ $reminder->contact_count }}{{ $reminder->contacted_at ? ' (dernière le '.$reminder->contacted_at->format('d/m/Y').')' : '' }}</strong></li>
        </ul>
    </div>

    <div class="card" data-share data-track="{{ route('maintenance.track', $reminder) }}">
        @csrf
        <h2>Message</h2>
        <p class="muted small">Texte prêt, modifiable avant l'envoi (modèle dans <a href="{{ route('settings.emails') }}#sms">Réglages → Emails</a>).</p>
        <div class="field">
            <label for="maintenance-message">Message</label>
            <textarea id="maintenance-message" rows="8" data-share-message>{{ $message }}</textarea>
        </div>
        <div class="share-buttons">
            <a class="btn" href="#" data-share-to="whatsapp" data-phone="{{ $whatsappPhone }}" @if (! $phone) aria-disabled="true" @endif><x-icon name="message" /> WhatsApp</a>
            <a class="btn" href="#" data-share-to="sms" data-phone="{{ $phone }}"><x-icon name="message" /> SMS</a>
            @if ($reminder->client->email)
                <a class="btn" href="{{ route('emails.create', ['client' => $reminder->client->id, 'entretien' => $reminder->id]) }}"><x-icon name="mail" /> Email</a>
            @endif
            <button class="btn btn-secondary" type="button" data-share-to="copy"><x-icon name="copy" /> Copier</button>
            @if ($phone)<a class="btn btn-secondary" href="tel:{{ $phone }}"><x-icon name="phone" /> Appeler</a>@endif
        </div>
    </div>

    <div class="card">
        <h2>Suite</h2>
        <div class="action-bar">
            <a class="btn" href="{{ route('quotes.create', ['client' => $reminder->client->id]) }}"><x-icon name="file" /> Faire un devis</a>
            @foreach (in_array($reminder->status, ['done', 'dismissed'], true)
                ? ['reopen' => 'Remettre à relancer']
                : ['done' => 'Terminé (devis fait ou refus)', 'later' => 'Reporter d\'un an', 'dismissed' => 'Ignorer'] as $action => $label)
                <form method="POST" action="{{ route('maintenance.update', $reminder) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="action" value="{{ $action }}">
                    <button class="btn btn-secondary" type="submit">{{ $label }}</button>
                </form>
            @endforeach
        </div>
    </div>
@endsection
