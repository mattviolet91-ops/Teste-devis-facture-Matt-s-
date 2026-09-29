<nav class="chips stats-tabs" aria-label="Statistiques">
    <a class="chip {{ request()->routeIs('statistics') ? 'is-active' : '' }}" href="{{ route('statistics') }}" @if (request()->routeIs('statistics')) aria-current="page" @endif>Clients</a>
    <a class="chip {{ request()->routeIs('site-stats.*') ? 'is-active' : '' }}" href="{{ route('site-stats.index') }}" @if (request()->routeIs('site-stats.*')) aria-current="page" @endif>Site internet</a>
</nav>
