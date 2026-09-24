@extends('layouts.app', ['title' => $intervention->isAppointment() ? 'Rendez-vous' : 'Intervention'])

@php
    $client = $intervention->client;
    $phone = preg_replace('/\D/', '', (string) $client?->phone);
    $whatsappPhone = preg_match('/^0\d{9}$/', $phone) ? '33'.substr($phone, 1) : $phone;
    $address = $intervention->address();
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $intervention->title }}</h1>
            <p><span class="badge">{{ $intervention->isAppointment() ? 'Rendez-vous' : 'Chantier' }}</span>
                @if ($client)<a href="{{ route('clients.show', $client) }}">{{ $client->displayName() }}</a>@endif
                · <span class="badge">{{ $intervention->statusLabel() }}</span></p>
        </div>
    </div>

    <div class="card">
        <ul class="stat-list">
            <li><span>Quand</span><strong>{{ ucfirst($intervention->whenLabel()) }}</strong></li>
            @if ($address)<li><span>Adresse</span><a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($address) }}" target="_blank" rel="noopener">{{ $address }}</a></li>@endif
            @if ($client?->phone)<li><span>Téléphone</span><a href="tel:{{ $phone }}">{{ $client->phone }}</a></li>@endif
            <li><span>Rappel</span><strong>{{ \App\Models\Intervention::REMINDERS[$intervention->remind_days ?? 0] ?? '—' }}{{ $intervention->remind_client && $client?->email ? ' · email au client' : '' }}{{ $intervention->reminder_sent_at ? ' (envoyé le '.$intervention->reminder_sent_at->format('d/m').')' : '' }}</strong></li>
            @if ($intervention->quote)<li><span>Devis</span><a href="{{ route('quotes.show', $intervention->quote) }}">{{ $intervention->quote->number }}</a></li>@endif
        </ul>
        @if ($intervention->notes)<p class="pre-line">{{ $intervention->notes }}</p>@endif
        <div class="action-bar">
            <a class="btn btn-secondary" href="{{ route('planning.edit', $intervention) }}">Modifier</a>
            @if ($intervention->status === 'planned')
                <form method="POST" action="{{ route('planning.update', $intervention) }}">
                    @csrf
                    @method('PUT')
                    @foreach (['kind', 'client_id', 'worksite_id', 'quote_id', 'location', 'title', 'start_time', 'end_time', 'notes'] as $f)<input type="hidden" name="{{ $f }}" value="{{ $intervention->$f }}">@endforeach
                    <input type="hidden" name="starts_on" value="{{ $intervention->starts_on->toDateString() }}">
                    <input type="hidden" name="ends_on" value="{{ $intervention->ends_on->toDateString() }}">
                    <input type="hidden" name="status" value="done">
                    <button class="btn btn-secondary" type="submit"><x-icon name="check" /> Fait</button>
                </form>
            @endif
            <a class="btn btn-secondary" href="{{ route('planning.ics', $intervention) }}"><x-icon name="calendar" /> Ajouter à mon agenda</a>
            <a class="btn btn-secondary" href="{{ route('planning.index', ['date' => $intervention->starts_on->toDateString()]) }}">Voir la semaine</a>
            <form method="POST" action="{{ route('planning.destroy', $intervention) }}" data-confirm="Supprimer du planning ?">
                @csrf
                @method('DELETE')
                <button class="btn btn-secondary" type="submit"><x-icon name="trash" /> Supprimer</button>
            </form>
        </div>
    </div>

    @if ($message)
    <div class="card" data-share>
        @csrf
        <h2>Prévenir le client</h2>
        <p class="muted small">Message de confirmation prêt, modifiable (modèle dans <a href="{{ route('settings.emails') }}#sms">Réglages → Emails</a>).</p>
        <div class="field">
            <label for="intervention-message">Message</label>
            <textarea id="intervention-message" rows="7" data-share-message>{{ $message }}</textarea>
        </div>
        <div class="share-buttons">
            <a class="btn" href="#" data-share-to="whatsapp" data-phone="{{ $whatsappPhone }}" @if (! $phone) aria-disabled="true" @endif><x-icon name="message" /> WhatsApp</a>
            <a class="btn" href="#" data-share-to="sms" data-phone="{{ $phone }}"><x-icon name="message" /> SMS</a>
            <button class="btn btn-secondary" type="button" data-share-to="copy"><x-icon name="copy" /> Copier</button>
        </div>
    </div>
    @endif
    @if ($intervention->isAppointment() && $client)
        <div class="action-bar">
            <a class="btn" href="{{ route('quotes.create', ['client' => $client->id]) }}"><x-icon name="file" /> Faire le devis</a>
        </div>
    @endif
@endsection
