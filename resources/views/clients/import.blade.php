@extends('layouts.app', ['title' => 'Importer des contacts'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Importer des contacts</h1>
            <p>Depuis Wix (Contacts → Plus d'actions → Exporter) ou un tableau CSV.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('clients.import.preview') }}" enctype="multipart/form-data" class="card">
        @csrf
        @error('file')<div class="alert alert-error">{{ $message }}</div>@enderror
        <p class="muted small">Un aperçu s'affiche avant tout enregistrement. Les contacts déjà présents (même téléphone ou même email) sont ignorés.
            Colonnes reconnues : Prénom, Nom, Email, Téléphone, Adresse, Code postal, Ville, Message, Commentaires.</p>
        <div class="field">
            <label for="file">Fichier « contacts.csv »</label>
            <input id="file" type="file" name="file" accept=".csv,text/csv,text/plain" required>
        </div>
        <div class="form-actions"><button class="btn" type="submit">Voir l'aperçu</button></div>
    </form>

    <div class="card">
        <h2>Devis et factures Wix</h2>
        <p class="muted small">Pour importer les PDF de vos anciens devis et factures Wix (avec leur numéro d'origine) :</p>
        <a class="btn btn-secondary" href="{{ route('archives.index') }}">Archives Wix</a>
    </div>
@endsection
