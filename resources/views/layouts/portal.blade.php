@extends('layouts.base')

{{-- Espace client : page publique aux couleurs de l'entreprise, sans menu de gestion. --}}
@section('body')
    @php $company = $settings->group('company'); @endphp
    <header class="portal-head">
        <div class="portal-wrap">
            <a href="{{ route('portal.home') }}" aria-label="Accueil"><x-brand-logo variant="full" /></a>
            <a class="portal-call" href="tel:{{ preg_replace('/\s+/', '', $company['phone']) }}"><x-icon name="phone" /> {{ $company['phone'] }}</a>
        </div>
    </header>
    <main class="portal-wrap portal-main">
        @if (request()->query('envoi') === 'incomplet')
            <div class="alert alert-warning" role="alert">L'envoi n'est pas arrivé en entier (réseau instable). Vérifiez puis recommencez si besoin.</div>
        @endif
        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif
        @yield('content')
    </main>
    <footer class="portal-wrap portal-foot small muted">
        <nav class="portal-links" aria-label="Informations">
            <a href="{{ route('portal.home') }}">Accueil</a>
            <a href="{{ route('portal.payment') }}">Paiement en ligne</a>
            <a href="{{ route('portal.cgv') }}">Conditions générales de vente</a>
            <a href="{{ route('portal.refunds') }}">Remboursement et annulation</a>
            <a href="{{ route('portal.legal') }}">Mentions légales</a>
        </nav>
        {{ $company['trade_name'] }} ({{ $company['legal_form'] }})@if (! empty($company['legal_name'])) — raison sociale : {{ $company['legal_name'] }}@endif — {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }}<br>
        SIRET {{ $company['siret'] }} · {{ $company['phone'] }} · {{ $company['email'] }}@if (! empty($company['website'])) · <a href="{{ $company['website'] }}">{{ preg_replace('#^https?://#', '', $company['website']) }}</a>@endif<br>
        Paiement par carte sécurisé par myPOS (Visa, Mastercard, CB).
    </footer>
@endsection
