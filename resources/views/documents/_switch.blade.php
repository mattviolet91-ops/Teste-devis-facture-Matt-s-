{{-- Sélecteur Demandes / Devis / Factures en haut des listes. Attend : $current ('requests', 'quotes' ou 'invoices'). --}}
@php $newRequests = \App\Models\QuoteRequest::query()->where('status', 'new')->whereHas('client')->count(); @endphp
<nav class="segmented segmented-3" aria-label="Demandes, devis ou factures">
    <a href="{{ route('requests.index') }}" class="{{ $current === 'requests' ? 'is-active' : '' }}" @if ($current === 'requests') aria-current="page" @endif>
        <x-icon name="mail" /> Demandes @if ($newRequests)<span class="count-badge">{{ $newRequests }}</span>@endif
    </a>
    <a href="{{ route('quotes.index') }}" class="{{ $current === 'quotes' ? 'is-active' : '' }}" @if ($current === 'quotes') aria-current="page" @endif><x-icon name="file" /> Devis</a>
    <a href="{{ route('invoices.index') }}" class="{{ $current === 'invoices' ? 'is-active' : '' }}" @if ($current === 'invoices') aria-current="page" @endif><x-icon name="receipt" /> Factures</a>
</nav>
