@extends('layouts.portal', ['title' => 'Mentions légales — '.$settings->get('company.trade_name')])

@php $company = $settings->group('company'); @endphp

@section('content')
    <article class="card legal-text">
        <h1>Mentions légales</h1>
        <h2>Éditeur</h2>
        <p>{{ $company['trade_name'] }}, {{ $company['legal_form'] === 'EI' ? 'entreprise individuelle (EI)' : $company['legal_form'] }}<br>
            {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }}<br>
            SIRET {{ $company['siret'] }}@if (! empty($company['ape_code'])) — APE {{ $company['ape_code'] }}@endif
            @if (! empty($company['vat_number']))<br>TVA intracommunautaire {{ $company['vat_number'] }}@endif
            <br>Téléphone {{ $company['phone'] }} — Email {{ $company['email'] }}</p>
        <h2>Hébergeur</h2>
        <p>{{ $company['host'] ?? '' }}</p>
        <h2>Paiement en ligne</h2>
        <p>Les paiements par carte bancaire sont traités par myPOS, établissement de monnaie électronique agréé. Les données de carte sont saisies sur la page sécurisée de myPOS et ne sont jamais transmises à {{ $company['trade_name'] }}.</p>
        <h2>Données personnelles</h2>
        <p>Les informations que vous nous confiez (nom, coordonnées, adresse des travaux) servent uniquement à établir les devis, réaliser les travaux et les facturer. Elles sont conservées pendant la durée légale (10 ans pour les factures) et ne sont transmises qu'au comptable ou à l'assureur si nécessaire. Vous pouvez y accéder, les corriger ou demander leur suppression en écrivant à {{ $company['email'] }}.</p>
        <p>Ce site n'utilise pas de cookie publicitaire ni de mesure d'audience nominative. Seul un cookie technique, indispensable à la sécurité des formulaires, est déposé.</p>
        @if (! empty($company['mediator_name']))
            <h2>Médiation</h2>
            <p>En cas de litige, vous pouvez recourir gratuitement au médiateur de la consommation : {{ $company['mediator_name'] }}@if (! empty($company['mediator_url'])) — <a href="{{ $company['mediator_url'] }}">{{ preg_replace('#^https?://#', '', $company['mediator_url']) }}</a>@endif.</p>
        @endif
    </article>
@endsection
