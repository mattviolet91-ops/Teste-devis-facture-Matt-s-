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

    @if ($checklist)
        @php $done = count(array_filter($checklist, fn ($i) => $i['done'])); @endphp
        <details class="card guide-section getting-started" id="bien-demarrer" @if ($done < count($checklist)) open @endif>
            <summary><x-icon name="check" /> <span>Bien démarrer</span> <span class="badge">{{ $done }} / {{ count($checklist) }}</span></summary>
            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($checklist) }}" aria-valuenow="{{ $done }}"><span style="width: {{ round($done * 100 / count($checklist)) }}%"></span></div>
            <p class="muted small">{{ $done === count($checklist) ? 'Tout est prêt.' : 'Vérifié automatiquement : chaque point se coche tout seul une fois réglé.' }}</p>
            <ul class="checklist">
                @foreach ($checklist as $item)
                    <li class="{{ $item['done'] ? 'is-done' : '' }}">
                        <span class="check-dot" aria-hidden="true">@if ($item['done'])<x-icon name="check" />@endif</span>
                        <span><strong>{{ $item['label'] }}</strong><span class="visually-hidden">{{ $item['done'] ? ' (fait)' : ' (à faire)' }}</span><br><span class="muted small">{{ $item['hint'] }}</span></span>
                        @unless ($item['done'])<a class="btn btn-secondary btn-sm" href="{{ $item['url'] }}">Régler</a>@endunless
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <p class="muted" data-guide-empty hidden>Rien trouvé dans le guide. Essayez un autre mot.</p>

    @foreach ($sections as $section)
        <details class="card guide-section" id="{{ $section['id'] }}" data-guide-section>
            <summary><x-icon :name="$section['icon']" /> <span>{{ $section['title'] }}</span></summary>
            <p>{{ $section['intro'] }}</p>
            @isset($section['image'])
                <figure class="guide-figure">
                    <img class="guide-shot" src="{{ asset('images/guide/'.$section['image']) }}" alt="{{ $section['caption'] }}" width="585" height="1140" loading="lazy" decoding="async">
                    <figcaption class="muted small">{{ $section['caption'] }}</figcaption>
                </figure>
            @endisset
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
