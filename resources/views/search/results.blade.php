@if (trim($q) === '')
    <p class="muted">Tapez au moins un mot : nom, numéro de téléphone (même partiel), email, rue ou ville.</p>
@elseif ($results['clients']->isEmpty() && $results['worksites']->isEmpty())
    <div class="card empty">
        <x-icon name="search" />
        <h2>Aucun résultat pour « {{ $q }} »</h2>
        <p><a class="btn" href="{{ route('clients.create') }}">Créer un client</a></p>
    </div>
@else
    @if ($results['clients']->isNotEmpty())
        <h2 class="section-title">Clients</h2>
        <ul class="list">
            @foreach ($results['clients'] as $client)
                <li>
                    <a class="list-item" href="{{ route('clients.show', $client) }}">
                        <span class="avatar">{{ $client->initials() }}</span>
                        <span class="list-main">
                            <strong>{{ $client->displayName() }}</strong>
                            <span class="muted small">{{ collect([$client->phone, $client->email, $client->city])->filter()->implode(' · ') }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
    @if ($results['worksites']->isNotEmpty())
        <h2 class="section-title">Chantiers</h2>
        <ul class="list">
            @foreach ($results['worksites'] as $worksite)
                <li>
                    <a class="list-item" href="{{ route('clients.show', $worksite->client_id) }}#chantier-{{ $worksite->id }}">
                        <span class="avatar avatar-muted"><x-icon name="home" /></span>
                        <span class="list-main">
                            <strong>{{ $worksite->fullAddress() }}</strong>
                            <span class="muted small">{{ $worksite->client->displayName() }}@if ($worksite->label) · {{ $worksite->label }}@endif</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endif
