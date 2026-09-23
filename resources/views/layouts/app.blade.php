@extends('layouts.base')

@php
    $nav = [
        ['dashboard', 'Accueil', 'home', route('dashboard')],
        ['clients', 'Clients', 'users', route('clients.index')],
        ['devis', 'Devis', 'file', route('quotes.index')],
        ['factures', 'Factures', 'receipt', route('invoices.index')],
        ['paiements', 'Paiements', 'wallet', route('module', 'paiements')],
        ['photos', 'Photos', 'camera', route('photos.index')],
        ['prestations', 'Prestations', 'book', route('catalog.index')],
        ['emails', 'Emails', 'mail', route('emails.index')],
    ];
    $isActive = fn (string $key) => match ($key) {
        'dashboard' => request()->routeIs('dashboard'),
        'clients' => request()->routeIs('clients.*', 'worksites.*'),
        'devis' => request()->routeIs('quotes.*'),
        'factures' => request()->routeIs('invoices.*'),
        'prestations' => request()->routeIs('catalog.*'),
        'emails' => request()->routeIs('emails.*'),
        'photos' => request()->routeIs('photos.*'),
        default => request()->route('module') === $key,
    };
@endphp

@section('body')
<div class="app">
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
            <a class="nav-link {{ request()->routeIs('trash.*') ? 'is-active' : '' }}" href="{{ route('trash.index') }}">
                <x-icon name="trash" /> Corbeille
            </a>
            <a class="nav-link {{ request()->routeIs('settings.*') ? 'is-active' : '' }}" href="{{ route('settings.company') }}">
                <x-icon name="settings" /> Réglages
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
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-error" role="alert">Certains champs sont à corriger.</div>
                @endif
                @yield('content')
            </div>
        </main>
    </div>

    <nav class="bottom-nav" aria-label="Navigation">
        <a href="{{ route('dashboard') }}" class="{{ $isActive('dashboard') ? 'is-active' : '' }}"><x-icon name="home" /> Accueil</a>
        <a href="{{ route('clients.index') }}" class="{{ $isActive('clients') ? 'is-active' : '' }}"><x-icon name="users" /> Clients</a>
        <button type="button" class="fab" data-open-sheet="sheet-new"><span class="fab-circle"><x-icon name="plus" /></span> Nouveau</button>
        <a href="{{ route('quotes.index') }}" class="{{ $isActive('devis') || $isActive('factures') ? 'is-active' : '' }}"><x-icon name="file" /> Devis</a>
        <button type="button" data-open-sheet="sheet-more" class="{{ request()->routeIs('settings.*') ? 'is-active' : '' }}"><x-icon name="menu" /> Plus</button>
    </nav>

    <dialog class="sheet" id="sheet-new" aria-labelledby="sheet-new-title">
        <div class="card-head">
            <h2 id="sheet-new-title">Créer</h2>
            <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
        </div>
        <div class="sheet-grid">
            <a class="sheet-item" href="{{ route('quotes.create') }}"><x-icon name="file" /> Devis</a>
            <a class="sheet-item" href="{{ route('invoices.create') }}"><x-icon name="receipt" /> Facture</a>
            <a class="sheet-item" href="{{ route('clients.create') }}"><x-icon name="users" /> Client</a>
            <a class="sheet-item" href="{{ route('photos.index') }}"><x-icon name="camera" /> Photo</a>
            <a class="sheet-item" href="{{ route('module', 'paiements') }}"><x-icon name="wallet" /> Paiement</a>
        </div>
    </dialog>

    <dialog class="sheet" id="sheet-more" aria-labelledby="sheet-more-title">
        <div class="card-head">
            <h2 id="sheet-more-title">Menu</h2>
            <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
        </div>
        <div class="sheet-list">
            <a href="{{ route('invoices.index') }}"><x-icon name="receipt" /> Factures</a>
            <a href="{{ route('module', 'paiements') }}"><x-icon name="wallet" /> Paiements</a>
            <a href="{{ route('photos.index') }}"><x-icon name="camera" /> Photos</a>
            <a href="{{ route('catalog.index') }}"><x-icon name="book" /> Prestations</a>
            <a href="{{ route('emails.index') }}"><x-icon name="mail" /> Emails</a>
            <a href="{{ route('trash.index') }}"><x-icon name="trash" /> Corbeille</a>
            <a href="{{ route('settings.company') }}"><x-icon name="settings" /> Réglages</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"><x-icon name="logout" /> Se déconnecter</button>
            </form>
        </div>
    </dialog>
</div>
@endsection
