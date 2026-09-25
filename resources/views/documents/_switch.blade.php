{{-- Sélecteur Devis / Factures en haut des listes. Attend : $current ('quotes' ou 'invoices'). --}}
<nav class="segmented" aria-label="Devis ou factures">
    <a href="{{ route('quotes.index') }}" class="{{ $current === 'quotes' ? 'is-active' : '' }}" @if ($current === 'quotes') aria-current="page" @endif><x-icon name="file" /> Devis</a>
    <a href="{{ route('invoices.index') }}" class="{{ $current === 'invoices' ? 'is-active' : '' }}" @if ($current === 'invoices') aria-current="page" @endif><x-icon name="receipt" /> Factures</a>
</nav>
