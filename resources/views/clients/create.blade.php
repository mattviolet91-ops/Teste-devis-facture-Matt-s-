@extends('layouts.app', ['title' => 'Nouveau client'])

@section('content')
    <div class="page-head">
        <h1>Nouveau client</h1>
    </div>
    <form method="POST" action="{{ route('clients.store') }}">
        @csrf
        @include('clients._form')
        <div class="form-actions sticky-actions">
            <button class="btn" type="submit">Enregistrer le client</button>
            <a class="btn btn-secondary" href="{{ route('clients.index') }}">Annuler</a>
        </div>
    </form>
@endsection
