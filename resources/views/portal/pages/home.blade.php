@extends('layouts.portal', ['title' => $settings->get('company.trade_name').' — Espace client'])

@php $company = $settings->group('company'); $pdf = $settings->group('pdf'); @endphp

@section('content')
    <div class="card">
        <h1>{{ $company['trade_name'] }}</h1>
        @if (! empty($company['slogan']))<p class="muted" style="margin-top:0">{{ $company['slogan'] }}</p>@endif
        @if (! empty($pdf['presentation_text']))<p class="pre-line">{{ $pdf['presentation_text'] }}</p>@endif
        @if (! empty($company['agreements']))<p class="small">{{ $company['agreements'] }}</p>@endif
        <div class="chips chips-wrap" style="margin-top:.75rem">
            <a class="btn" href="{{ route('portal.request') }}"><x-icon name="file" /> Demander un devis</a>
            <a class="btn btn-secondary" href="{{ route('portal.payment') }}"><x-icon name="wallet" /> Régler une facture</a>
        </div>
    </div>

    <div class="card">
        <h2>Espace client</h2>
        <p>Avec chaque devis et chaque facture, vous recevez un lien personnel pour les consulter, signer le devis en ligne et télécharger le PDF.</p>
        <h3 class="small muted" style="margin-bottom:.25rem">Paiement en ligne</h3>
        <p style="margin-top:0">Les factures et les acomptes peuvent être réglés par carte bancaire sur la page de paiement sécurisée de notre prestataire myPOS. Vos données de carte ne nous sont jamais transmises.</p>
        @include('portal.pages._cards')
        <p class="small"><a href="{{ route('portal.payment') }}">Comment payer en ligne</a> · <a href="{{ route('portal.refunds') }}">Remboursement et annulation</a> · <a href="{{ route('portal.cgv') }}">Conditions générales de vente</a></p>
    </div>

    <div class="card">
        <h2>Nous contacter</h2>
        <ul class="stat-list">
            <li><span>Téléphone</span><a href="tel:{{ preg_replace('/\s+/', '', $company['phone']) }}">{{ $company['phone'] }}</a></li>
            <li><span>Email</span><a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></li>
            <li><span>Adresse</span><span>{{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }}</span></li>
            @if (! empty($company['website']))<li><span>Site internet</span><a href="{{ $company['website'] }}">{{ preg_replace('#^https?://#', '', $company['website']) }}</a></li>@endif
        </ul>
    </div>
@endsection
