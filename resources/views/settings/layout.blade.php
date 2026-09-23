@extends('layouts.app')

@php
    $tabs = [
        'settings.company' => 'Entreprise',
        'settings.branding' => 'Apparence',
        'settings.vat' => 'TVA & unités',
        'settings.numbering' => 'Numérotation',
        'settings.documents' => 'Documents PDF',
        'settings.insurance' => 'Assurance',
        'settings.emails' => 'Emails',
        'settings.texts' => 'Textes types',
        'settings.account' => 'Mon compte',
    ];
@endphp

@section('content')
    <div class="page-head"><h1>Réglages</h1></div>
    <nav class="tabs" aria-label="Rubriques des réglages">
        @foreach ($tabs as $route => $label)
            <a href="{{ route($route) }}" class="{{ request()->routeIs($route) ? 'is-active' : '' }}" @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    @yield('settings')
@endsection
