@extends('layouts.app', ['title' => 'Photos'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Photos</h1>
            <p>Les photos sont rangées par chantier.</p>
        </div>
    </div>

    <form method="GET" class="card" data-go-worksite>
        <div class="field">
            <label for="worksite-go">Ajouter des photos au chantier…</label>
            <select id="worksite-go" data-go-select>
                <option value="">— Choisir un chantier —</option>
                @foreach ($worksites as $worksite)
                    <option value="{{ route('photos.worksite', $worksite) }}">{{ $worksite->client->displayName() }} — {{ $worksite->fullAddress() }}</option>
                @endforeach
            </select>
        </div>
    </form>

    <div class="chips" role="group" aria-label="Catégorie" style="margin-bottom:1rem">
        <a class="chip {{ ! $category ? 'is-active' : '' }}" href="{{ route('photos.index') }}">Toutes</a>
        @foreach (\App\Models\Photo::CATEGORIES as $key => $label)
            <a class="chip {{ $category === $key ? 'is-active' : '' }}" href="{{ route('photos.index', ['categorie' => $key]) }}">{{ $label }}</a>
        @endforeach
    </div>

    @if ($photos->isEmpty())
        <div class="card empty"><x-icon name="camera" /><h2>Aucune photo</h2><p class="muted">Choisissez un chantier ci-dessus pour en ajouter.</p></div>
    @else
        @include('photos._grid', ['photos' => $photos, 'manage' => false])
        {{ $photos->links('components.pagination') }}
    @endif
    <script src="{{ asset('js/photos.js') }}?v={{ filemtime(public_path('js/photos.js')) }}" defer></script>
@endsection
