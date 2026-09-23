@extends('layouts.app', ['title' => $client->displayName()])

@php
    use App\Models\Client;
    use App\Models\Worksite;
    use App\Support\Phone;
@endphp

@section('content')
    <div class="client-head card">
        <span class="avatar avatar-lg">{{ $client->initials() }}</span>
        <div class="client-head-main">
            <h1>{{ $client->displayName() }}</h1>
            <p class="muted">
                {{ Client::TYPES[$client->type] }}
                @if ($client->contactName()) · Contact : {{ $client->contactName() }} @endif
            </p>
            <span class="badge {{ $client->status === 'client' ? 'badge-success' : '' }}">{{ Client::STATUSES[$client->status] }}</span>
        </div>
        <a class="btn btn-secondary btn-sm" href="{{ route('clients.edit', $client) }}">Modifier</a>
    </div>

    <div class="quick-actions">
        @if ($client->phone)
            <a class="quick-action" href="{{ Phone::href($client->phone) }}"><x-icon name="phone" /> Appeler</a>
            <a class="quick-action" href="sms:{{ preg_replace('/\s/', '', $client->phone) }}"><x-icon name="message" /> SMS</a>
        @endif
        @if ($client->email)
            <a class="quick-action" href="mailto:{{ $client->email }}"><x-icon name="mail" /> Email</a>
        @endif
        <span class="quick-action is-disabled" title="Disponible à la phase 6"><x-icon name="file" /> Nouveau devis</span>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>Coordonnées</h2></div>
            <dl class="details">
                @if ($client->phone)<dt>Téléphone</dt><dd><a href="{{ Phone::href($client->phone) }}">{{ $client->phone }}</a></dd>@endif
                @if ($client->phone_2)<dt>Autre téléphone</dt><dd><a href="{{ Phone::href($client->phone_2) }}">{{ $client->phone_2 }}</a></dd>@endif
                @if ($client->email)<dt>Email</dt><dd><a href="mailto:{{ $client->email }}">{{ $client->email }}</a></dd>@endif
                @if ($client->fullAddress())<dt>Adresse</dt><dd>{{ $client->fullAddress() }}</dd>@endif
                @if ($client->source)<dt>Provenance</dt><dd>{{ Client::SOURCES[$client->source] }}@if ($client->source_detail) — {{ $client->source_detail }}@endif</dd>@endif
                <dt>Créé le</dt><dd>{{ $client->created_at->format('d/m/Y') }}</dd>
            </dl>
            @if ($client->notes)
                <h3>Notes</h3>
                <p class="pre-line">{{ $client->notes }}</p>
            @endif
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Chantiers</h2>
                <a class="btn btn-secondary btn-sm" href="{{ route('worksites.create', $client) }}"><x-icon name="plus" /> Ajouter</a>
            </div>
            @forelse ($client->worksites as $worksite)
                <article class="worksite" id="chantier-{{ $worksite->id }}">
                    <div class="worksite-head">
                        <div>
                            <strong>{{ $worksite->label ?: 'Chantier' }}</strong>
                            <div>{{ $worksite->fullAddress() }}</div>
                        </div>
                        <a class="btn btn-secondary btn-sm" href="{{ route('worksites.edit', $worksite) }}">Modifier</a>
                    </div>
                    <div class="chips">
                        <a class="chip" href="{{ $worksite->mapsUrl() }}" target="_blank" rel="noopener"><x-icon name="map" /> Itinéraire</a>
                        @if ($worksite->contact_phone)
                            <a class="chip" href="{{ Phone::href($worksite->contact_phone) }}"><x-icon name="phone" /> {{ $worksite->contact_name ?: 'Contact sur place' }}</a>
                        @endif
                    </div>
                    @if ($worksite->hasRoofDetails())
                        <dl class="details compact">
                            @if ($worksite->roof_type)<dt>Couverture</dt><dd>{{ Worksite::ROOF_TYPES[$worksite->roof_type] }}</dd>@endif
                            @if ($worksite->roof_surface)<dt>Surface</dt><dd>{{ rtrim(rtrim(number_format((float) $worksite->roof_surface, 2, ',', ' '), '0'), ',') }} m²</dd>@endif
                            @if ($worksite->roof_pitch)<dt>Pente</dt><dd>{{ $worksite->roof_pitch }}</dd>@endif
                            @if ($worksite->levels !== null)<dt>Niveaux</dt><dd>{{ $worksite->levels }}</dd>@endif
                            @if ($worksite->accessibility)<dt>Accès</dt><dd>{{ Worksite::ACCESSIBILITY[$worksite->accessibility] }}</dd>@endif
                        </dl>
                    @endif
                    @if ($worksite->access_notes)
                        <p class="small pre-line"><strong>Accès :</strong> {{ $worksite->access_notes }}</p>
                    @endif
                    @if ($worksite->notes)
                        <p class="small muted pre-line">{{ $worksite->notes }}</p>
                    @endif
                </article>
            @empty
                <p class="muted">Aucun chantier. <a href="{{ route('worksites.create', $client) }}">Ajouter l'adresse des travaux</a></p>
            @endforelse
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Documents</h2></div>
        <ul class="stat-list">
            <li><span>Devis</span><span class="muted small">phase 6</span></li>
            <li><span>Factures</span><span class="muted small">phase 7</span></li>
            <li><span>Paiements</span><span class="muted small">phase 10</span></li>
            <li><span>Photos</span><span class="muted small">phase 9</span></li>
        </ul>
    </div>

    <div class="card">
        <div class="card-head"><h2>Historique</h2></div>
        @if ($history->isEmpty())
            <p class="muted">Aucun événement.</p>
        @else
            <ol class="timeline">
                @foreach ($history as $event)
                    <li>
                        <span class="muted small">{{ $event->created_at->format('d/m/Y H:i') }}</span>
                        <span>{{ $event->description }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
@endsection
