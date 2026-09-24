@extends('layouts.app', ['title' => $item->exists ? $item->name : 'Nouvelle prestation'])

@section('content')
    <div class="page-head">
        <h1>{{ $item->exists ? 'Modifier la prestation' : 'Nouvelle prestation' }}</h1>
    </div>

    <form method="POST" action="{{ $item->exists ? route('catalog.update', $item) : route('catalog.store') }}">
        @csrf
        @if ($item->exists) @method('PUT') @endif
        <div class="card">
            <div class="form-grid cols-2">
                <x-field name="name" label="Nom de la prestation" :value="$item->name" class="span-2" required />
                <x-select name="category_id" label="Catégorie" :options="$categories" :value="$item->category_id" placeholder="Sans catégorie" />
                <x-select name="unit" label="Unité" :options="$units->mapWithKeys(fn ($label, $code) => [$code => $code.' — '.$label])" :value="$item->unit" :placeholder="false" />
                <x-field name="unit_price" label="Prix unitaire HT (€)" :value="$item->unit_price ? \App\Support\LineInput::money($item->unit_price) : ''" inputmode="decimal" placeholder="0,00" />
                <x-select name="vat_rate" label="TVA" :options="$vatRates->mapWithKeys(fn ($r) => [$r->rate => $r->label])" :value="$item->vat_rate" placeholder="Taux par défaut" hint="Utile seulement si vous êtes assujetti à la TVA." />
                <x-select name="maintenance_months" label="Relance d'entretien" :options="[12 => 'Après 1 an', 18 => 'Après 18 mois', 24 => 'Après 2 ans', 36 => 'Après 3 ans', 48 => 'Après 4 ans', 60 => 'Après 5 ans', 120 => 'Après 10 ans']" :value="$item->maintenance_months" placeholder="Pas de relance" hint="Ex. démoussage : 3 ans. Un rappel est créé à chaque facture qui contient cette prestation." />
                <x-field name="description" label="Détail des étapes (une par ligne)" type="textarea" :value="$item->description" class="span-2" rows="10" />
                <label class="check span-2">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active))>
                    <span>Proposer cette prestation dans l'éditeur de devis</span>
                </label>
            </div>
        </div>
        <div class="form-actions sticky-actions">
            <button class="btn" type="submit">Enregistrer</button>
            <a class="btn btn-secondary" href="{{ route('catalog.index') }}">Annuler</a>
        </div>
    </form>

    @if ($item->exists)
        <form method="POST" action="{{ route('catalog.destroy', $item) }}" data-confirm="Supprimer définitivement cette prestation de la bibliothèque ? Les devis existants ne sont pas modifiés.">
            @csrf
            @method('DELETE')
            <div class="form-actions"><button class="btn btn-danger-outline" type="submit">Supprimer la prestation</button></div>
        </form>
    @endif
@endsection
