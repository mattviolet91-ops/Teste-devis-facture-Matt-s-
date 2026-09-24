@extends('layouts.app', ['title' => 'Nouveau client'])

@section('content')
    <div class="page-head">
        <h1>Nouveau client</h1>
    </div>
    <form method="POST" action="{{ route('clients.store') }}" data-offline="Nouveau client">
        @csrf
        @if (session('duplicates'))
            <div class="alert alert-warning" role="alert">
                <strong>Une fiche existe peut-être déjà avec ce téléphone ou cet email :</strong>
                <ul class="duplicates">
                    @foreach (session('duplicates') as $duplicate)
                        <li><a href="{{ $duplicate['url'] }}">{{ $duplicate['name'] }}</a> <span class="small">{{ $duplicate['detail'] }}</span></li>
                    @endforeach
                </ul>
                <label class="check">
                    <input type="checkbox" name="confirm_duplicate" value="1">
                    <span>Ce n'est pas la même personne : créer quand même la fiche</span>
                </label>
            </div>
        @endif
        @include('clients._form')
        <div class="form-actions sticky-actions">
            <button class="btn" type="submit">Enregistrer le client</button>
            <a class="btn btn-secondary" href="{{ route('clients.index') }}">Annuler</a>
        </div>
    </form>
@endsection
