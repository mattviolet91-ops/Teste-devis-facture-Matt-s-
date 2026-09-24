@extends('layouts.app', ['title' => 'Modifier '.$client->displayName()])

@section('content')
    <div class="page-head">
        <h1>Modifier la fiche</h1>
    </div>
    <form method="POST" action="{{ route('clients.update', $client) }}" data-offline="Fiche client">
        @csrf
        @method('PUT')
        @include('clients._form')
        <div class="form-actions sticky-actions">
            <button class="btn" type="submit">Enregistrer</button>
            <a class="btn btn-secondary" href="{{ route('clients.show', $client) }}">Annuler</a>
        </div>
    </form>

    <form method="POST" action="{{ route('clients.destroy', $client) }}" data-confirm="Mettre ce client et ses chantiers à la corbeille ? Vous pourrez les restaurer pendant 30 jours.">
        @csrf
        @method('DELETE')
        @error('client')<div class="alert alert-warning" role="alert">{{ $message }}</div>@enderror
        <div class="form-actions">
            <button class="btn btn-danger-outline" type="submit">Mettre à la corbeille</button>
        </div>
    </form>
@endsection
