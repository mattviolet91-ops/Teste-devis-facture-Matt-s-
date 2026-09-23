{{-- Modèles de lignes, bibliothèque et script de l'éditeur (à placer après le formulaire). --}}
@php
    use App\Support\LineInput;

    $partialData = compact('units', 'vatRates', 'steps', 'franchise');
    $clientData = $clients->mapWithKeys(fn ($c) => [$c->id => $c->worksites->map(fn ($w) => ['id' => $w->id, 'label' => $w->label ? $w->label.' — '.$w->fullAddress() : $w->fullAddress()])->values()]);
    $editorData = ['catalog' => $catalog, 'franchise' => $franchise, 'defaultVatRate' => $defaultVatRate, 'worksites' => $clientData];
@endphp

@foreach (['item', 'section', 'text'] as $type)
    <template id="tpl-line-{{ $type }}">
        @include('quotes._line', ['i' => '__i__', 'l' => LineInput::blank($type, $defaultVatRate)] + $partialData)
    </template>
@endforeach

<dialog class="sheet sheet-tall" id="catalog-dialog" aria-labelledby="catalog-title">
    <div class="card-head">
        <h2 id="catalog-title">Bibliothèque de prestations</h2>
        <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
    </div>
    <label class="search-field" for="catalog-search">
        <x-icon name="search" /><span class="visually-hidden">Rechercher une prestation</span>
        <input id="catalog-search" type="search" placeholder="Rechercher : démoussage, faîtière, Velux…" autocomplete="off">
    </label>
    <div class="catalog-list" data-catalog-list></div>
    <p class="small muted" style="margin-top:.75rem"><a href="{{ route('catalog.index') }}">Gérer la bibliothèque</a></p>
</dialog>

<script type="application/json" id="quote-editor-data">@json($editorData)</script>
<script src="{{ asset('js/quote-editor.js') }}?v={{ filemtime(public_path('js/quote-editor.js')) }}" defer></script>
