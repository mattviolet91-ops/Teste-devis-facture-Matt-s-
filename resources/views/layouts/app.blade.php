@extends('layouts.base')

@php
    $nav = [
        ['dashboard', 'Accueil', 'home', route('dashboard')],
        ['clients', 'Clients', 'users', route('module', 'clients')],
        ['devis', 'Devis', 'file', route('module', 'devis')],
        ['factures', 'Factures', 'receipt', route('module', 'factures')],
        ['paiements', 'Paiements', 'wallet', route('module', 'paiements')],
        ['photos', 'Photos', 'camera', route('module', 'photos')],
        ['prestations', 'Prestations', 'book', route('module', 'prestations')],
    ];
    $isActive = fn (string $key) => $key === 'dashboard'
        ? request()->routeIs('dashboard')
        : (request()->route('module') === $key);
@endphp

@section('body')
<div class="app">
    <header class="topbar">
        <a class="brand" href="{{ route('dashboard') }}">
            <x-brand-logo />
            <span class="brand-name">{{ $settings->get('company.trade_name') }}</span>
        </a>
        <label class="search" for="global-search">
            <x-icon name="search" />
            <span class="visually-hidden">Rechercher</span>
            <input id="global-search" type="search" placeholder="Client, n° de devis, adresse… (bientôt)" disabled>
        </label>
        <span class="spacer"></span>
        <button class="icon-btn" type="button" data-theme-toggle title="Mode clair / sombre">
            <x-icon name="moon" /><span class="visually-hidden">Mode clair / sombre</span>
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
        <a href="{{ route('module', 'clients') }}" class="{{ $isActive('clients') ? 'is-active' : '' }}"><x-icon name="users" /> Clients</a>
        <button type="button" class="fab" data-open-sheet="sheet-new"><span class="fab-circle"><x-icon name="plus" /></span> Nouveau</button>
        <a href="{{ route('module', 'devis') }}" class="{{ $isActive('devis') || $isActive('factures') ? 'is-active' : '' }}"><x-icon name="file" /> Documents</a>
        <button type="button" data-open-sheet="sheet-more" class="{{ request()->routeIs('settings.*') ? 'is-active' : '' }}"><x-icon name="menu" /> Plus</button>
    </nav>

    <dialog class="sheet" id="sheet-new" aria-labelledby="sheet-new-title">
        <div class="card-head">
            <h2 id="sheet-new-title">Créer</h2>
            <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
        </div>
        <div class="sheet-grid">
            <a class="sheet-item" href="{{ route('module', 'devis') }}"><x-icon name="file" /> Devis</a>
            <a class="sheet-item" href="{{ route('module', 'clients') }}"><x-icon name="users" /> Client</a>
            <a class="sheet-item" href="{{ route('module', 'photos') }}"><x-icon name="camera" /> Photo</a>
            <a class="sheet-item" href="{{ route('module', 'paiements') }}"><x-icon name="wallet" /> Paiement</a>
        </div>
    </dialog>

    <dialog class="sheet" id="sheet-more" aria-labelledby="sheet-more-title">
        <div class="card-head">
            <h2 id="sheet-more-title">Menu</h2>
            <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
        </div>
        <div class="sheet-list">
            <a href="{{ route('module', 'factures') }}"><x-icon name="receipt" /> Factures</a>
            <a href="{{ route('module', 'paiements') }}"><x-icon name="wallet" /> Paiements</a>
            <a href="{{ route('module', 'photos') }}"><x-icon name="camera" /> Photos</a>
            <a href="{{ route('module', 'prestations') }}"><x-icon name="book" /> Prestations</a>
            <a href="{{ route('settings.company') }}"><x-icon name="settings" /> Réglages</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"><x-icon name="logout" /> Se déconnecter</button>
            </form>
        </div>
    </dialog>
</div>
@endsection
