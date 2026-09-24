@extends('layouts.app', ['title' => 'Demande de '.$request->client->displayName()])

@php
    $client = $request->client;
    $phone = preg_replace('/\D/', '', (string) $client->phone);
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Demande de devis</h1>
            <p><a href="{{ route('clients.show', $client) }}">{{ $client->displayName() }}</a> · reçue le {{ $request->created_at->format('d/m/Y à H:i') }}
                · <span class="badge">{{ $request->status === 'new' ? 'à traiter' : 'traitée' }}</span></p>
        </div>
    </div>

    <div class="quick-actions">
        @if ($phone)<a class="quick-action" href="tel:{{ $phone }}"><x-icon name="phone" /> Appeler</a>
            <a class="quick-action" href="sms:{{ $phone }}"><x-icon name="message" /> SMS</a>@endif
        <a class="quick-action" href="{{ route('planning.create', ['type' => 'rdv', 'client' => $client->id]) }}"><x-icon name="calendar" /> Rendez-vous</a>
        <a class="quick-action" href="{{ route('quotes.create', ['client' => $client->id]) }}"><x-icon name="file" /> Faire le devis</a>
    </div>

    <div class="card">
        <ul class="stat-list">
            <li><span>Travaux</span><strong>{{ $request->worksLabel() ?: '—' }}</strong></li>
            <li><span>Téléphone</span><strong>{{ $client->phone ?: '—' }}</strong></li>
            @if ($client->email)<li><span>Email</span><strong>{{ $client->email }}</strong></li>@endif
            @if ($request->worksite)<li><span>Adresse</span><a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($request->worksite->fullAddress()) }}" target="_blank" rel="noopener">{{ $request->worksite->fullAddress() }}</a></li>@endif
            @if ($request->availability)<li><span>Disponibilités</span><strong>{{ $request->availability }}</strong></li>@endif
        </ul>
        @if ($request->message)<p class="pre-line">{{ $request->message }}</p>@endif
    </div>

    @if ($client->photos->isNotEmpty())
        <div class="card">
            <h2>Photos</h2>
            <div class="photo-grid">
                @foreach ($client->photos->take(10) as $photo)
                    <a href="{{ route('photos.file', [$photo, 'photo']) }}" target="_blank" rel="noopener"><img src="{{ route('photos.file', [$photo, 'mini']) }}" alt="Photo envoyée" loading="lazy"></a>
                @endforeach
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('requests.toggle', $request) }}">
        @csrf
        <button class="btn {{ $request->status === 'new' ? '' : 'btn-secondary' }}" type="submit"><x-icon name="check" /> {{ $request->status === 'new' ? 'Marquer comme traitée' : 'Remettre à traiter' }}</button>
    </form>
@endsection
