@extends('layouts.base')

@php
    $nav = [
        ['dashboard', 'Accueil', 'home', route('dashboard')],
        ['clients', 'Clients', 'users', route('clients.index')],
        ['devis', 'Devis', 'file', route('quotes.index')],
        ['demandes', 'Demandes', 'mail', route('requests.index')],
        ['factures', 'Factures', 'receipt', route('invoices.index')],
        ['paiements', 'Paiements', 'wallet', route('payments.index')],
        ['frais', 'Frais', 'cart', route('expenses.index')],
        ['relances', 'Relances', 'send', route('reminders.index')],
        ['planning', 'Planning', 'calendar', route('planning.index')],
        ['entretiens', 'Entretiens', 'tool', route('maintenance.index')],
        ['statistiques', 'Statistiques', 'chart', route('statistics')],
        ['photos', 'Photos', 'camera', route('photos.index')],
        ['prestations', 'Prestations', 'book', route('catalog.index')],
        ['emails', 'Emails', 'mail', route('emails.index')],
    ];
    // Compte commercial : uniquement les pages qu'il peut ouvrir.
    $user = auth()->user();
    $nav = array_values(array_filter($nav, fn ($item) => ! isset(\App\Support\Navigation::ITEMS[$item[0]]) || $user->canOpen(\App\Support\Navigation::ITEMS[$item[0]][2])));
    $isActive = fn (string $key) => match ($key) {
        'dashboard' => request()->routeIs('dashboard'),
        'clients' => request()->routeIs('clients.*', 'worksites.*'),
        'devis' => request()->routeIs('quotes.*'),
        'demandes' => request()->routeIs('requests.*'),
        'factures' => request()->routeIs('invoices.*'),
        'prestations' => request()->routeIs('catalog.*'),
        'emails' => request()->routeIs('emails.*'),
        'photos' => request()->routeIs('photos.*'),
        'paiements' => request()->routeIs('payments.*'),
        'frais' => request()->routeIs('expenses.*'),
        'relances' => request()->routeIs('reminders.*'),
        'statistiques' => request()->routeIs('statistics'),
        'entretiens' => request()->routeIs('maintenance.*'),
        'planning' => request()->routeIs('planning.*'),
        default => false,
    };
@endphp

@section('body')
<div class="app" data-offline-root data-token-url="{{ route('offline.token') }}" data-pages-url="{{ route('offline.pages') }}">
    <header class="topbar">
        <a class="brand" href="{{ route('dashboard') }}">
            <x-brand-logo />
            <span class="brand-name">{{ $settings->get('company.trade_name') }}</span>
        </a>
        <form class="search" method="GET" action="{{ route('search') }}" role="search">
            <label for="global-search"><x-icon name="search" /><span class="visually-hidden">Rechercher</span></label>
            <input id="global-search" type="search" name="q" value="{{ request()->routeIs('search') ? request('q') : '' }}" placeholder="Client, n° de devis ou de facture, téléphone, adresse…">
        </form>
        <span class="spacer"></span>
        <a class="icon-btn" href="{{ \App\Support\Guide::urlFor(request()->route()?->getName()) }}" title="Aide sur cette page"><x-icon name="help" /><span class="visually-hidden">Aide sur cette page</span></a>
        <a class="icon-btn search-mobile" href="{{ route('search') }}" title="Rechercher"><x-icon name="search" /><span class="visually-hidden">Rechercher</span></a>
        <button class="icon-btn" type="button" data-theme-toggle title="Mode clair / sombre">
            <x-icon name="moon" class="icon theme-moon" /><x-icon name="sun" class="icon theme-sun" /><span class="visually-hidden">Mode clair / sombre</span>
        </button>
    </header>

    <div class="layout">
        <nav class="sidebar" aria-label="Navigation principale">
            <button class="btn" type="button" data-open-sheet="sheet-new"><x-icon name="plus" /> Nouveau</button>
            @foreach ($nav as [$key, $label, $icon, $url])
                <a class="nav-link {{ $isActive($key) ? 'is-active' : '' }}" href="{{ $url }}" @if ($isActive($key)) aria-current="page" @endif>
                    <x-icon :name="$icon" /> {{ $label }}
                </a>
            @endforeach
            <span class="nav-sep"></span>
            @if ($user->isAdmin())
                <a class="nav-link {{ request()->routeIs('trash.*') ? 'is-active' : '' }}" href="{{ route('trash.index') }}">
                    <x-icon name="trash" /> Corbeille
                </a>
            @endif
            <a class="nav-link {{ request()->routeIs('guide') ? 'is-active' : '' }}" href="{{ route('guide') }}">
                <x-icon name="book" /> Guide
            </a>
            <a class="nav-link {{ request()->routeIs('settings.*') ? 'is-active' : '' }}" href="{{ route($user->isAdmin() ? 'settings.company' : 'settings.account') }}">
                <x-icon name="settings" /> {{ $user->isAdmin() ? 'Réglages' : 'Mon compte' }}
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="nav-link" type="submit">
                    <x-icon name="logout" /> Se déconnecter
                </button>
            </form>
        </nav>

        <main class="main">
            <div class="container">
                <div class="offline-bar" data-offline-bar hidden role="status">
                    <span data-offline-text></span>
                    <button class="btn btn-sm btn-secondary" type="button" data-offline-open hidden>Voir</button>
                </div>
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-error" role="alert">Certains champs sont à corriger.</div>
                @endif
                @if ($backUrl = \App\Support\BackLink::for(request()))
                    <a class="back-link" href="{{ $backUrl }}" data-back><x-icon name="chevron-left" /> Retour</a>
                @endif
                @yield('content')
            </div>
        </main>
    </div>

    <nav class="bottom-nav" aria-label="Navigation">
        @foreach (\App\Support\Navigation::bottom() as $i => $key)
            @php [$label, $icon, $route] = \App\Support\Navigation::ITEMS[$key]; @endphp
            @if ($i === 2)
                <button type="button" class="fab" data-open-sheet="sheet-new"><span class="fab-circle"><x-icon name="plus" /></span> Nouveau</button>
            @endif
            <a href="{{ route($route) }}" class="{{ \App\Support\Navigation::isActive($key) ? 'is-active' : '' }}"><x-icon :name="$icon" /> {{ $label }}</a>
        @endforeach
        <button type="button" data-open-sheet="sheet-more" class="{{ request()->routeIs('settings.*') ? 'is-active' : '' }}"><x-icon name="menu" /> Plus</button>
    </nav>

    <dialog class="sheet" id="sheet-new" aria-labelledby="sheet-new-title">
        <div class="card-head">
            <h2 id="sheet-new-title">Créer</h2>
            <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
        </div>
        <div class="sheet-grid">
            <a class="sheet-item" href="{{ route('quotes.create') }}"><x-icon name="file" /> Devis</a>
            @if ($user->isAdmin())<a class="sheet-item" href="{{ route('invoices.create') }}"><x-icon name="receipt" /> Facture</a>@endif
            <a class="sheet-item" href="{{ route('clients.create') }}"><x-icon name="users" /> Client</a>
            <a class="sheet-item" href="{{ route('photos.index') }}"><x-icon name="camera" /> Photo</a>
            @if ($user->isAdmin())<a class="sheet-item" href="{{ route('payments.index') }}"><x-icon name="wallet" /> Paiement</a>@endif
            @if ($user->isAdmin())<a class="sheet-item" href="{{ route('expenses.create') }}"><x-icon name="cart" /> Frais</a>@endif
            <a class="sheet-item" href="{{ route('planning.create', ['type' => 'rdv']) }}"><x-icon name="calendar" /> Rendez-vous</a>
        </div>
    </dialog>

    <dialog class="sheet" id="sheet-more" aria-labelledby="sheet-more-title">
        <div class="card-head">
            <h2 id="sheet-more-title">Menu</h2>
            <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
        </div>
        @php
            // 6 raccourcis principaux (hors ceux déjà dans la barre du bas), le reste dans « Autres ».
            $inBar = \App\Support\Navigation::bottom();
            $coveredByDocuments = in_array('documents', $inBar, true) ? ['devis', 'factures', 'demandes'] : [];
            $priority = ['planning', 'paiements', 'relances', 'photos', 'statistiques', 'entretiens', 'frais', 'avis', 'demandes', 'devis', 'factures', 'clients', 'prestations', 'emails'];
            $available = array_values(array_filter($priority, fn ($k) => ! in_array($k, $inBar, true) && ! in_array($k, $coveredByDocuments, true)
                && $user->canOpen(\App\Support\Navigation::ITEMS[$k][2])));
            $main = array_slice($available, 0, 6);
            $others = array_slice($available, 6);
        @endphp
        <div class="sheet-grid">
            @foreach ($main as $key)
                @php [$label, $icon, $route] = \App\Support\Navigation::ITEMS[$key]; @endphp
                <a class="sheet-item" href="{{ route($route) }}"><x-icon :name="$icon" /> {{ $key === 'statistiques' ? 'Statistiques' : $label }}</a>
            @endforeach
        </div>
        <details class="sheet-more">
            <summary>Autres</summary>
            <div class="sheet-list">
                @foreach ($others as $key)
                    @php [$label, $icon, $route] = \App\Support\Navigation::ITEMS[$key]; @endphp
                    <a href="{{ route($route) }}"><x-icon :name="$icon" /> {{ $key === 'avis' ? 'Avis Google' : $label }}</a>
                @endforeach
                @if ($user->isAdmin())
                    <a href="{{ route('archives.index') }}"><x-icon name="file" /> Archives Wix</a>
                    <a href="{{ route('trash.index') }}"><x-icon name="trash" /> Corbeille</a>
                @endif
            </div>
        </details>
        <div class="pref-toggles" role="group" aria-label="Affichage sur ce téléphone">
            <button class="pref-toggle" type="button" data-pref-toggle="big" aria-pressed="false"><span class="pref-switch" aria-hidden="true"></span> Grands boutons</button>
        </div>
        <label class="nav-pref" for="nav-pref"><x-icon name="map" /> GPS
            <select id="nav-pref" data-nav-pref>
                <option value="">Demander à chaque fois</option>
                <option value="apple">Apple Plans</option>
                <option value="waze">Waze</option>
                <option value="google">Google Maps</option>
            </select>
        </label>
        <div class="sheet-footer">
            <a class="btn btn-secondary sheet-footer-wide" href="{{ route('guide') }}"><x-icon name="book" /> Guide d'utilisation</a>
            <a class="btn btn-secondary" href="{{ route($user->isAdmin() ? 'settings.company' : 'settings.account') }}"><x-icon name="settings" /> {{ $user->isAdmin() ? 'Réglages' : 'Mon compte' }}</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn btn-secondary" type="submit"><x-icon name="logout" /> Se déconnecter</button>
            </form>
        </div>
    </dialog>
    <dialog class="sheet" id="nav-dialog" aria-labelledby="nav-title">
        <div class="card-head">
            <h2 id="nav-title">Y aller avec…</h2>
            <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
        </div>
        <p class="muted small" data-nav-address></p>
        <div class="nav-apps">
            <button class="btn btn-secondary" type="button" data-nav-app="apple"><x-icon name="map" /> Apple Plans</button>
            <button class="btn btn-secondary" type="button" data-nav-app="waze"><x-icon name="map" /> Waze</button>
            <button class="btn btn-secondary" type="button" data-nav-app="google"><x-icon name="map" /> Google Maps</button>
        </div>
        <label class="check" style="margin-top:.75rem"><input type="checkbox" data-nav-remember> <span>Toujours utiliser cette application sur ce téléphone</span></label>
        <p class="muted small" style="margin-bottom:0">Modifiable ensuite dans le menu Plus.</p>
    </dialog>

    <dialog class="sheet" id="offline-dialog" aria-labelledby="offline-title">
        <div class="card-head">
            <h2 id="offline-title">Envois en attente</h2>
            <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
        </div>
        <p class="small muted">Saisis sans réseau et gardés sur ce téléphone. Ils partent tout seuls dès que le réseau revient.</p>
        <div data-offline-list></div>
        <div class="form-actions"><button class="btn" type="button" data-offline-send>Envoyer maintenant</button></div>
    </dialog>
    <script src="{{ asset('js/offline.js') }}?v={{ filemtime(public_path('js/offline.js')) }}" defer></script>
</div>
@endsection
