@extends('layouts.app', ['title' => 'Statistiques du site'])

@php
    use App\Models\SiteEvent;
    $maxVisits = max(1, $series->max('visits'));
    $wpMax = max(1, $wp['series']->max('views'));
    $typeLabel = fn ($type) => ['tel' => 'Appeler', 'whatsapp' => 'WhatsApp', 'form' => 'Formulaire', 'cta' => 'Bouton', 'mail' => 'Email'][$type] ?? $type;
    $fmtDay = fn ($day) => \Illuminate\Support\Carbon::parse($day)->locale('fr')->isoFormat('D MMM');
@endphp

@section('content')
    @include('statistics._tabs')
    <div class="page-head">
        <div>
            <h1>Site internet</h1>
            <p>Visites et prises de contact sur {{ $siteHost ?: 'votre site' }}, {{ \App\Http\Controllers\SiteStatsController::PERIODS[$days] }} (depuis le {{ $from->format('d/m/Y') }}).</p>
        </div>
    </div>

    <div class="period-bar">
        <div class="chips" role="group" aria-label="Période">
            @foreach (\App\Http\Controllers\SiteStatsController::PERIODS as $key => $label)
                <a class="chip {{ (string) $days === (string) $key ? 'is-active' : '' }}" href="{{ route('site-stats.index', ['jours' => $key]) }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    @error('wpcom')<div class="alert alert-error" role="alert">{{ $message }}</div>@enderror

    @if (! $siteHost)
        <div class="alert alert-warning">Indiquez d'abord l'adresse de votre site dans <a href="{{ route('settings.company') }}">Réglages → Entreprise</a> : seules les visites de ce site sont comptées.</div>
    @endif

    {{-- Compteur de l'application --}}
    <div class="grid grid-3">
        <div class="card kpi kpi-accent"><span class="label">Visites</span><span class="value">{{ $totals['visits'] }}</span><span class="muted small">{{ $totals['views'] }} pages vues</span></div>
        <div class="card kpi kpi-accent"><span class="label">Prises de contact</span><span class="value">{{ $totals['contacts'] }}</span>
            <span class="muted small">appels, emails, WhatsApp, formulaires{{ $totals['rate'] !== null ? ' · '.str_replace('.', ',', $totals['rate']).' % des visites' : '' }}</span></div>
        <div class="card kpi kpi-accent"><span class="label">Demandes de devis reçues</span><span class="value">{{ $totals['requests'] }}</span><a class="small" href="{{ route('requests.index') }}">Voir les demandes</a></div>
    </div>

    <div class="card" style="margin-top:1rem">
        <div class="card-head"><h2>Visites par jour</h2><span class="small muted">max {{ $maxVisits }}</span></div>
        @if ($totals['visits'] === 0)
            <p class="muted" style="margin:0">Aucune visite comptée sur cette période{{ $lastEvent ? '' : ' : le compteur n\'est pas encore installé sur le site (voir ci-dessous)' }}.</p>
        @else
            <div class="day-bars" role="img" aria-label="Visites par jour du {{ $fmtDay($series->first()['day']) }} au {{ $fmtDay($series->last()['day']) }}">
                @foreach ($series as $point)
                    <span class="day-bar" title="{{ $fmtDay($point['day']) }} : {{ $point['visits'] }} visite(s), {{ $point['views'] }} page(s) vue(s)"><span style="height: {{ $point['visits'] ? max(3, (int) round($point['visits'] * 100 / $maxVisits)) : 0 }}%"></span></span>
                @endforeach
            </div>
            <div class="day-bars-axis small muted"><span>{{ $fmtDay($series->first()['day']) }}</span><span>{{ $fmtDay($series->last()['day']) }}</span></div>
        @endif
    </div>

    <div class="grid grid-2" style="margin-top:1rem">
        <div class="card">
            <h2>Clics de contact</h2>
            <ul class="stat-list">
                @foreach (['tel', 'mail', 'whatsapp', 'form', 'cta'] as $type)
                    <li><span>{{ SiteEvent::TYPES[$type] }}</span><strong>{{ (int) ($byType[$type] ?? 0) }}</strong></li>
                @endforeach
            </ul>
            @if ($buttons->isNotEmpty())
                <h3 class="small muted" style="margin-top:1rem">Boutons les plus cliqués</h3>
                <ul class="stat-list">
                    @foreach ($buttons as $button)
                        <li><span>{{ $button->label }} <span class="muted small">· {{ $typeLabel($button->type) }}</span></span><strong>{{ $button->n }}</strong></li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="card">
            <h2>D'où viennent les visiteurs</h2>
            @if ($sources->isEmpty())
                <p class="muted" style="margin:0">Pas encore de données.</p>
            @else
                @php $maxSource = max(1, $sources->max()); @endphp
                <ul class="hbars">
                    @foreach ($sources as $label => $n)
                        <li><span class="hbar-label">{{ $label }}</span><span class="hbar-track" aria-hidden="true"><span class="hbar-fill" style="width: {{ max(1, (int) round($n * 100 / $maxSource)) }}%"></span></span><span class="hbar-value">{{ $n }}</span></li>
                    @endforeach
                </ul>
            @endif
            @if ($devices->isNotEmpty())
                <p class="small muted" style="margin:.75rem 0 0">Sur téléphone : {{ (int) ($devices['mobile'] ?? 0) }} visite(s) · sur ordinateur : {{ (int) ($devices['ordinateur'] ?? 0) }}.</p>
            @endif
        </div>
    </div>

    @if ($pages->isNotEmpty())
        <div class="card" style="margin-top:1rem">
            <h2>Pages les plus vues</h2>
            <ul class="stat-list">
                @foreach ($pages as $path => $n)
                    <li><span class="page-path">{{ $path }}</span><strong>{{ $n }}</strong></li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card" style="margin-top:1rem" id="installation">
        <div class="card-head"><h2>Compteur sur le site</h2>
            <span class="badge {{ $lastEvent ? 'badge-success' : 'badge-warning' }}">{{ $lastEvent ? 'Actif · dernière visite le '.$lastEvent->format('d/m à H:i') : 'Pas encore installé' }}</span></div>
        <details @unless ($lastEvent) open @endunless>
            <summary>Comment l'installer (une seule fois, 2 minutes)</summary>
            <ol class="howto-list">
                <li>Sur ordinateur, ouvrez votre site dans WordPress, puis <strong>Elementor → Code personnalisé → Ajouter</strong>.</li>
                <li>Nom : « Statistiques ». Emplacement : <strong>&lt;head&gt;</strong>. Collez la ligne ci-dessous.</li>
                <li>Publiez, en choisissant « Tout le site ».</li>
                <li>Ouvrez votre site sur votre téléphone : la visite apparaît ici en quelques secondes.</li>
            </ol>
            <div class="copy-field">
                <input type="text" value="{{ $snippet }}" readonly aria-label="Code à coller dans le site" data-copy-source>
                <button class="btn btn-secondary btn-sm" type="button" data-copy>Copier</button>
            </div>
            <p class="small muted">Sans cookie et sans donnée personnelle (aucune adresse IP enregistrée) : pas besoin de bandeau de consentement. Les données sont gardées 13 mois.</p>
        </details>
    </div>

    {{-- WordPress.com (Jetpack Stats) --}}
    <div class="card" style="margin-top:1rem" id="wordpress">
        <div class="card-head"><h2>Statistiques WordPress.com</h2>
            @if ($wp['connected'])<span class="badge badge-success">Connecté</span>@endif</div>

        @if ($wp['connected'])
            <p class="small muted" style="margin-top:0">Visites comptées par WordPress.com, importées chaque matin.
                {{ $wp['lastSync'] ? 'Dernière mise à jour : '.\Illuminate\Support\Carbon::parse($wp['lastSync'])->format('d/m/Y à H:i').'.' : '' }}</p>
            @if ($wp['error'])<div class="alert alert-warning">{{ $wp['error'] }}</div>@endif
            <ul class="stat-list">
                <li><span>Visiteurs ({{ \App\Http\Controllers\SiteStatsController::PERIODS[$days] }})</span><strong>{{ $wp['totalVisitors'] }}</strong></li>
                <li><span>Pages vues</span><strong>{{ $wp['totalViews'] }}</strong></li>
            </ul>
            @if ($wp['totalViews'] > 0)
                <div class="day-bars day-bars-wp" role="img" aria-label="Pages vues par jour selon WordPress.com">
                    @foreach ($wp['series'] as $point)
                        <span class="day-bar" title="{{ $fmtDay($point['day']) }} : {{ $point['views'] }} page(s) vue(s), {{ $point['visitors'] }} visiteur(s)"><span style="height: {{ $point['views'] ? max(3, (int) round($point['views'] * 100 / $wpMax)) : 0 }}%"></span></span>
                    @endforeach
                </div>
            @endif
            @foreach (['pages' => 'Pages les plus vues (30 jours)', 'referrers' => 'Provenance (30 jours)', 'clicks' => 'Liens cliqués (30 jours)'] as $key => $title)
                @if (! empty($wp['top'][$key]))
                    <h3 class="small muted" style="margin-top:1rem">{{ $title }}</h3>
                    <ul class="stat-list">
                        @foreach ($wp['top'][$key] as $row)
                            <li><span class="page-path">{{ $row['title'] }}</span><strong>{{ $row['views'] }}</strong></li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
            <div class="action-bar">
                <form method="POST" action="{{ route('site-stats.wpcom.sync') }}">@csrf<button class="btn btn-secondary btn-sm" type="submit">Mettre à jour maintenant</button></form>
                <form method="POST" action="{{ route('site-stats.wpcom.disconnect') }}" data-confirm="Déconnecter le compte WordPress.com ?">@csrf @method('DELETE')<button class="btn btn-secondary btn-sm" type="submit">Déconnecter</button></form>
            </div>
        @else
            <p class="small muted" style="margin-top:0">Pour voir aussi les visites déjà comptées par WordPress.com (l'historique), connectez une fois votre compte. Lecture seule : votre site n'est pas modifié.</p>
            <ol class="howto-list">
                <li>Sur ordinateur, ouvrez <strong>developer.wordpress.com/apps</strong> et connectez-vous avec votre compte WordPress.com, puis « Create New Application ».</li>
                <li>Name : « Gestion ». Website URL : l'adresse de votre site. Redirect URL : copiez l'adresse ci-dessous. Type : <strong>Web</strong>. Validez.</li>
                <li>Recopiez le <strong>Client ID</strong> et le <strong>Client Secret</strong> affichés dans le formulaire ci-dessous, puis appuyez sur « Connecter ».</li>
            </ol>
            <div class="copy-field">
                <input type="text" value="{{ $wp['callback'] }}" readonly aria-label="Redirect URL à indiquer" data-copy-source>
                <button class="btn btn-secondary btn-sm" type="button" data-copy>Copier</button>
            </div>
            <form method="POST" action="{{ route('site-stats.wpcom.app') }}" class="form-grid cols-2" style="margin-top:1rem">
                @csrf
                @method('PUT')
                <x-field name="client_id" label="Client ID" :value="$wp['clientId']" inputmode="numeric" required />
                <x-field name="client_secret" label="Client Secret" type="password" autocomplete="off" required :hint="$wp['hasApp'] ? 'Déjà enregistré (chiffré). Recollez-le seulement pour le changer.' : null" />
                <div class="span-2"><button class="btn btn-secondary" type="submit">Enregistrer</button></div>
            </form>
            @if ($wp['hasApp'])
                <div class="action-bar"><a class="btn" href="{{ route('site-stats.wpcom.connect') }}">Connecter mon compte WordPress.com</a></div>
            @endif
        @endif
    </div>
@endsection
