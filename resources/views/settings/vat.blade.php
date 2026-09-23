@extends('settings.layout', ['title' => 'TVA & unités'])

@section('settings')
<form method="POST" action="{{ route('settings.vat.regime') }}" class="card">
    @csrf
    @method('PUT')
    <fieldset>
        <legend>Régime de TVA</legend>
        <div class="radio-cards">
            <label class="radio-card">
                <input type="radio" name="regime" value="assujetti" @checked(old('regime', $vat['regime']) === 'assujetti')>
                <span><strong>Assujetti à la TVA</strong><br><span class="muted small">La TVA est calculée ligne par ligne selon les taux ci-dessous.</span></span>
            </label>
            <label class="radio-card">
                <input type="radio" name="regime" value="franchise" @checked(old('regime', $vat['regime']) === 'franchise')>
                <span><strong>Franchise en base</strong><br><span class="muted small">Aucune TVA facturée ; la mention ci-dessous est ajoutée aux documents.</span></span>
            </label>
        </div>
        <div class="form-grid" style="margin-top:1rem">
            <x-field name="franchise_mention" label="Mention de franchise" :value="$vat['franchise_mention']" required />
            <label class="check">
                <input type="checkbox" name="reduced_rate_mention_enabled" value="1" @checked(old('reduced_rate_mention_enabled', $vat['reduced_rate_mention_enabled']))>
                <span>Ajouter automatiquement la mention d'attestation du client quand un document contient un taux réduit (10 % ou 5,5 %)<br>
                <span class="muted small">Sans cette mention, l'administration peut réclamer la TVA à 20 %.</span></span>
            </label>
        </div>
        <div class="form-actions"><button class="btn" type="submit">Enregistrer le régime</button></div>
    </fieldset>
</form>

<div class="card">
    <div class="card-head"><h2>Taux de TVA</h2></div>
    <p class="muted small">La mention s'imprime sur les documents qui utilisent ce taux.</p>
    @foreach ($rates as $rate)
        <form method="POST" action="{{ route('settings.vat.rates.update', $rate) }}" class="row-form vat">
            @csrf
            @method('PUT')
            <div class="field"><label for="rate-label-{{ $rate->id }}">Libellé</label><input id="rate-label-{{ $rate->id }}" type="text" name="label" value="{{ $rate->label }}" required></div>
            <div class="field"><label for="rate-rate-{{ $rate->id }}">Taux (%)</label><input id="rate-rate-{{ $rate->id }}" type="text" inputmode="decimal" name="rate" value="{{ rtrim(rtrim(number_format($rate->rate / 100, 2, '.', ''), '0'), '.') }}" required></div>
            <div class="field"><label for="rate-mention-{{ $rate->id }}">Mention</label><input id="rate-mention-{{ $rate->id }}" type="text" name="mention" value="{{ $rate->mention }}"></div>
            <label class="check"><input type="checkbox" name="is_default" value="1" @checked($rate->is_default)> <span class="small">Par défaut</span></label>
            <label class="check"><input type="checkbox" name="is_active" value="1" @checked($rate->is_active)> <span class="small">Actif</span></label>
            <button class="btn btn-secondary btn-sm" type="submit">Enregistrer</button>
        </form>
    @endforeach
    @if ($errors->hasAny(['label', 'rate', 'mention']))
        <p class="error">{{ $errors->first('label') ?: $errors->first('rate') ?: $errors->first('mention') }}</p>
    @endif

    <details style="margin-top:1rem">
        <summary class="btn btn-secondary btn-sm">Ajouter un taux</summary>
        <form method="POST" action="{{ route('settings.vat.rates.store') }}" class="form-grid cols-2" style="margin-top:1rem">
            @csrf
            <div class="field"><label for="new-rate-label">Libellé</label><input id="new-rate-label" type="text" name="label" placeholder="TVA 8,5 %" required></div>
            <div class="field"><label for="new-rate-rate">Taux (%)</label><input id="new-rate-rate" type="text" inputmode="decimal" name="rate" placeholder="8.5" required></div>
            <div class="field span-2"><label for="new-rate-mention">Mention (facultatif)</label><input id="new-rate-mention" type="text" name="mention"></div>
            <label class="check"><input type="checkbox" name="is_default" value="1"> <span>Taux par défaut</span></label>
            <div><button class="btn btn-sm" type="submit">Ajouter</button></div>
        </form>
    </details>
</div>

<div class="card">
    <div class="card-head"><h2>Unités</h2></div>
    @foreach ($units as $unit)
        <form method="POST" action="{{ route('settings.units.update', $unit) }}" class="row-form unit">
            @csrf
            @method('PUT')
            <div class="field"><label for="unit-code-{{ $unit->id }}">Abréviation</label><input id="unit-code-{{ $unit->id }}" type="text" name="code" value="{{ $unit->code }}" required></div>
            <div class="field"><label for="unit-label-{{ $unit->id }}">Libellé</label><input id="unit-label-{{ $unit->id }}" type="text" name="label" value="{{ $unit->label }}" required></div>
            <label class="check"><input type="checkbox" name="is_active" value="1" @checked($unit->is_active)> <span class="small">Active</span></label>
            <button class="btn btn-secondary btn-sm" type="submit">Enregistrer</button>
        </form>
    @endforeach
    @if ($errors->hasAny(['code']))
        <p class="error">{{ $errors->first('code') }}</p>
    @endif

    <details style="margin-top:1rem">
        <summary class="btn btn-secondary btn-sm">Ajouter une unité</summary>
        <form method="POST" action="{{ route('settings.units.store') }}" class="form-grid cols-2" style="margin-top:1rem">
            @csrf
            <div class="field"><label for="new-unit-code">Abréviation</label><input id="new-unit-code" type="text" name="code" placeholder="m³" required></div>
            <div class="field"><label for="new-unit-label">Libellé</label><input id="new-unit-label" type="text" name="label" placeholder="Mètre cube" required></div>
            <div><button class="btn btn-sm" type="submit">Ajouter</button></div>
        </form>
    </details>
</div>
@endsection
