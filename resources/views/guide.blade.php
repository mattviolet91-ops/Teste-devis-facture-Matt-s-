@extends('layouts.app', ['title' => 'Guide'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Guide</h1>
            <p>Toutes les fonctionnalités de l'application, expliquées pas à pas. Touchez une rubrique pour l'ouvrir.</p>
        </div>
    </div>

    <div class="card">
        <label class="search-field" for="guide-q">
            <x-icon name="search" />
            <span class="visually-hidden">Chercher dans le guide</span>
            <input id="guide-q" type="search" placeholder="Chercher : acompte, signature, relance…" autocomplete="off" data-guide-search>
        </label>
    </div>

    <p class="muted" data-guide-empty hidden>Rien trouvé dans le guide. Essayez un autre mot.</p>

    @foreach ($sections as $section)
        <details class="card guide-section" id="{{ $section['id'] }}" data-guide-section>
            <summary><x-icon :name="$section['icon']" /> <span>{{ $section['title'] }}</span></summary>
            <p>{{ $section['intro'] }}</p>
            <ol class="guide-steps">
                @foreach ($section['steps'] as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
            @if ($section['tips'])
                <div class="guide-tips">
                    <strong>Bon à savoir</strong>
                    <ul>
                        @foreach ($section['tips'] as $tip)
                            <li>{{ $tip }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($section['route'] && $section['link'])
                <a class="btn btn-secondary btn-sm" href="{{ route($section['route']) }}">{{ $section['link'] }}</a>
            @endif
        </details>
    @endforeach

    <p class="muted small">Une question qui n'est pas dans le guide ? Demandez à Claude, il connaît l'application.</p>

    <script src="{{ asset('js/guide.js') }}?v={{ filemtime(public_path('js/guide.js')) }}" defer></script>
@endsection
