@extends('layouts.portal', ['title' => 'Demande envoyée'])

@section('content')
    <div class="card portal-status portal-ok">
        <h1><x-icon name="check" /> Merci, votre demande est bien envoyée</h1>
        <p>Nous vous rappelons rapidement pour convenir d'une visite. Pour une urgence (fuite, tuiles envolées), appelez-nous directement.</p>
        <p><a class="btn" href="tel:{{ preg_replace('/\s+/', '', $settings->get('company.phone')) }}"><x-icon name="phone" /> {{ $settings->get('company.phone') }}</a></p>
        @if ($settings->get('company.website'))<p><a href="{{ $settings->get('company.website') }}">Retour au site</a></p>@endif
    </div>
@endsection
