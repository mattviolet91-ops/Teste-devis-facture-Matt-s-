@extends('layouts.app', ['title' => 'Recherche'])

@section('content')
    <div class="page-head"><h1>Recherche</h1></div>
    <form method="GET" action="{{ route('search') }}" class="card" role="search">
        <label class="search-field" for="search-q">
            <x-icon name="search" />
            <span class="visually-hidden">Rechercher</span>
            <input id="search-q" type="search" name="q" value="{{ $q }}" placeholder="Nom, téléphone, email, adresse, ville…"
                autocomplete="off" autofocus data-live-search="search-results">
        </label>
    </form>
    <div id="search-results" aria-live="polite">
        @include('search.results')
    </div>
@endsection
