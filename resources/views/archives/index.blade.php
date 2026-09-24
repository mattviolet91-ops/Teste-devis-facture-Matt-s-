@extends('layouts.app', ['title' => 'Archives Wix'])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Archives Wix</h1>
            <p>Devis et factures émis avec Wix, conservés avec leur numéro d'origine. Ils ne comptent ni dans la numérotation ni dans le chiffre d'affaires de l'application.</p>
        </div>
    </div>

    @foreach (session('archive_errors', []) as $error)
        <div class="alert alert-warning small">{{ $error }}</div>
    @endforeach

    <form method="POST" action="{{ route('archives.store') }}" enctype="multipart/form-data" class="card">
        @csrf
        @error('files')<div class="alert alert-error">{{ $message }}</div>@enderror
        @error('files.*')<div class="alert alert-error">{{ $message }}</div>@enderror
        <h2>Importer des PDF Wix</h2>
        <p class="muted small">Sélectionnez les PDF de vos devis et factures Wix (ou le fichier .zip qui les contient). Chaque document est lu (numéro, date, montant, statut, client)
            et rangé sur la fiche du client, créé s'il n'existe pas encore. Un document déjà importé est ignoré.</p>
        <div class="field">
            <label for="archive-files">Fichiers PDF ou .zip</label>
            <input id="archive-files" type="file" name="files[]" accept="application/pdf,.zip,application/zip" multiple required>
        </div>
        <div class="form-actions"><button class="btn" type="submit">Importer</button></div>
    </form>

    <form method="GET" action="{{ route('archives.index') }}" class="card filters">
        <label class="search-field" for="q">
            <x-icon name="search" /><span class="visually-hidden">Rechercher</span>
            <input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="N° Wix, client, objet…" autocomplete="off">
        </label>
        <div class="chips" style="margin-top:.5rem">
            <a class="chip {{ ! $kind ? 'is-active' : '' }}" href="{{ route('archives.index') }}">Tout</a>
            @foreach (\App\Models\WixArchive::KINDS as $key => $label)
                @if (isset($totals[$key]))
                    <a class="chip {{ $kind === $key ? 'is-active' : '' }}" href="{{ route('archives.index', ['type' => $key]) }}">{{ $label }} ({{ $totals[$key]->count }})</a>
                @endif
            @endforeach
        </div>
    </form>

    @if ($archives->isEmpty())
        <div class="card empty"><x-icon name="file" /><h2>Aucune archive Wix</h2></div>
    @else
        <ul class="list">
            @foreach ($archives as $archive)
                <li>
                    <a class="list-item" href="{{ route('archives.show', $archive) }}" target="_blank" rel="noopener">
                        <span class="list-main">
                            <strong>{{ $archive->label() }} · {{ $archive->client?->displayName() ?? 'Client inconnu' }}</strong>
                            <span class="muted small">{{ $archive->title ?: 'Sans objet' }}{{ $archive->issue_date ? ' · '.$archive->issue_date->format('d/m/Y') : '' }}</span>
                        </span>
                        <span class="list-meta">
                            <strong class="amount">{{ Money::format($archive->total) }}</strong>
                            @if ($archive->status)<span class="badge">{{ $archive->status }}</span>@endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        {{ $archives->links('components.pagination') }}
    @endif
@endsection
