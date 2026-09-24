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

<dialog class="sheet sheet-tall" id="roof-dialog" aria-labelledby="roof-title" data-roof-dialog>
    <div class="card-head">
        <h2 id="roof-title">Surface de toiture</h2>
        <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
    </div>
    <p class="small muted">Un bloc par pan de toit. Mesure au sol (emprise) : indiquez la pente, la surface réelle est calculée. Mesure sur le rampant : laissez la pente vide.</p>
    <div data-roof-pans></div>
    <button type="button" class="btn btn-secondary btn-sm" data-roof-add><x-icon name="plus" /> Ajouter un pan</button>
    <div class="field" style="margin-top:.75rem">
        <label for="roof-deduct">À déduire (fenêtres de toit, cheminée…) en m²</label>
        <input id="roof-deduct" type="text" inputmode="decimal" placeholder="0" data-roof-deduct>
    </div>
    <p class="roof-result">Surface totale : <strong data-roof-total>0,00 m²</strong></p>
    <div class="form-actions"><button type="button" class="btn" data-roof-apply><x-icon name="check" /> Utiliser cette quantité</button></div>
</dialog>
<template id="tpl-roof-pan">
    <fieldset class="roof-pan" data-roof-pan>
        <legend class="small"><span data-roof-label>Pan</span> <button type="button" class="link-btn small" data-roof-remove>Retirer</button></legend>
        <div class="line-grid">
            <div class="field"><label>Longueur (m)</label><input type="text" inputmode="decimal" data-roof="length" placeholder="ex. 12,5"></div>
            <div class="field"><label>Largeur (m)</label><input type="text" inputmode="decimal" data-roof="width" placeholder="ex. 6"></div>
            <div class="field"><label>Pente</label><input type="text" inputmode="decimal" data-roof="slope" placeholder="vide = rampant"></div>
            <div class="field"><label>Unité de pente</label><select data-roof="slope_unit"><option value="deg">degrés (°)</option><option value="pct">pour cent (%)</option></select></div>
            <div class="field"><label>Nombre de pans identiques</label><input type="text" inputmode="numeric" data-roof="count" value="1"></div>
        </div>
        <p class="small muted" data-roof-pan-total></p>
    </fieldset>
</template>

<script type="application/json" id="quote-editor-data">@json($editorData)</script>
<script src="{{ asset('js/quote-editor.js') }}?v={{ filemtime(public_path('js/quote-editor.js')) }}" defer></script>
<script src="{{ asset('js/roof-calc.js') }}?v={{ filemtime(public_path('js/roof-calc.js')) }}" defer></script>
