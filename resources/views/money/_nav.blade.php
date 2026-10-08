{{-- En-tête de l'espace Argent : titre, mode discret, verrou, onglets. --}}
@php
    $tabs = [
        ['money.dashboard', 'Résumé', 'money.dashboard'],
        ['money.transactions.index', 'Mouvements', 'money.transactions.*'],
        ['money.accounts.index', 'Comptes', 'money.accounts.*'],
        ['money.categories.index', 'Budgets', 'money.categories.*'],
        ['money.goals.index', 'Objectifs', 'money.goals.*'],
        ['money.recurrings.index', 'Fixes', 'money.recurrings.*'],
        ['money.reports.index', 'Bilans', 'money.reports.*'],
    ];
@endphp
<div class="money-top" data-money-root data-lock-seconds="{{ app(\App\Services\MoneyLockService::class)->lockMinutes() * 60 }}" data-lock-url="{{ route('money.unlock') }}">
    <h1><x-icon name="piggy" /> Argent</h1>
    <span class="spacer"></span>
    <button class="icon-btn" type="button" data-money-discreet title="Mode discret : flouter les montants" aria-pressed="false">
        <x-icon name="eye-off" /><span class="visually-hidden">Mode discret</span>
    </button>
    <a class="icon-btn {{ request()->routeIs('money.settings') ? 'is-active' : '' }}" href="{{ route('money.settings') }}" title="Réglages Argent"><x-icon name="settings" /><span class="visually-hidden">Réglages Argent</span></a>
    <form method="POST" action="{{ route('money.lock') }}">
        @csrf
        <button class="btn btn-sm btn-secondary" type="submit" title="Verrouiller l'espace Argent"><x-icon name="lock" /> Verrouiller</button>
    </form>
</div>
<nav class="tabs money-tabs" aria-label="Espace Argent">
    @foreach ($tabs as [$route, $label, $pattern])
        <a href="{{ route($route) }}" class="{{ request()->routeIs($pattern) ? 'is-active' : '' }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
@error('sync')<div class="alert alert-error" role="alert">{{ $message }}</div>@enderror
@error('transaction')<div class="alert alert-error" role="alert">{{ $message }}</div>@enderror
<script src="{{ asset('js/money.js') }}?v={{ filemtime(public_path('js/money.js')) }}" defer></script>
