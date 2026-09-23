@extends('settings.layout', ['title' => 'Apparence'])

@php
    $colors = [
        'color_accent' => ['Couleur d\'accent', 'Boutons, liens, éléments mis en avant.', '--brand-accent'],
        'color_primary' => ['Couleur principale', 'Barre du haut, en-têtes des documents.', '--brand-primary'],
        'color_text' => ['Couleur du texte', null, '--brand-text'],
        'color_background' => ['Couleur de fond', null, '--brand-background'],
    ];
@endphp

@section('settings')
<form method="POST" action="{{ route('settings.branding') }}" enctype="multipart/form-data">
    @csrf

    <div class="card">
        <fieldset>
            <legend>Logo</legend>
            <div class="logo-preview">
                @if ($branding['logo_path'])
                    <img id="logo-preview" src="{{ route('branding.logo') }}?v={{ md5($branding['logo_path']) }}" alt="Logo actuel">
                @else
                    <img id="logo-preview" alt="Aperçu du logo" hidden>
                    <span class="muted small">Aucun logo : les initiales sont affichées.</span>
                @endif
            </div>
            <div class="field @error('logo') has-error @enderror" style="margin-top:.75rem">
                <label for="logo">Choisir une image</label>
                <input id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" data-preview="logo-preview">
                <span class="hint">PNG (fond transparent de préférence), JPG ou WebP — 2 Mo maximum.</span>
                @error('logo')<span class="error">{{ $message }}</span>@enderror
            </div>
            @if ($branding['logo_path'])
                <label class="check" style="margin-top:.75rem">
                    <input type="checkbox" name="remove_logo" value="1"> <span>Retirer le logo</span>
                </label>
            @endif
        </fieldset>
    </div>

    <div class="card">
        <fieldset>
            <legend>Couleurs</legend>
            <div class="form-grid cols-2">
                @foreach ($colors as $key => [$label, $hint, $cssVar])
                    <div class="field @error($key) has-error @enderror">
                        <label for="{{ $key }}">{{ $label }}</label>
                        <div class="color-field">
                            <input type="color" value="{{ old($key, $branding[$key]) }}" aria-label="{{ $label }} (sélecteur)">
                            <input id="{{ $key }}" type="text" name="{{ $key }}" value="{{ old($key, $branding[$key]) }}" maxlength="7" data-preview-var="{{ $cssVar }}" required>
                        </div>
                        @if ($hint)<span class="hint">{{ $hint }}</span>@endif
                        @error($key)<span class="error">{{ $message }}</span>@enderror
                    </div>
                @endforeach
            </div>
        </fieldset>
    </div>

    <div class="card">
        <fieldset>
            <legend>Polices</legend>
            <div class="form-grid cols-2">
                @foreach (['font_heading' => 'Titres', 'font_body' => 'Texte'] as $key => $label)
                    <div class="field">
                        <label for="{{ $key }}">{{ $label }}</label>
                        <select id="{{ $key }}" name="{{ $key }}">
                            @foreach ($fonts as $font)
                                <option value="{{ $font }}" @selected(old($key, $branding[$key]) === $font)>{{ $font }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
        </fieldset>
    </div>

    <div class="card">
        <h2>Aperçu d'un document</h2>
        <p class="muted small">La mise en page complète des PDF arrive à la phase 8.</p>
        <div class="preview-doc">
            <div class="preview-head" style="background: var(--brand-primary)">
                <strong style="font-family: var(--font-heading)">DEVIS DEV-2026-0001</strong>
                <span>{{ now()->format('d/m/Y') }}</span>
            </div>
            <div class="preview-body" style="font-family: var(--font-body); color: var(--brand-text)">
                <div class="preview-row"><span>Démoussage et traitement hydrofuge — 85 m²</span><span>1 190,00 €</span></div>
                <div class="preview-row"><span>Remplacement de tuiles — 12 u</span><span>264,00 €</span></div>
                <div class="preview-total" style="background: var(--brand-accent); color: #0B2530"><span>Total TTC</span><span>1 599,40 €</span></div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn" type="submit">Enregistrer</button>
    </div>
</form>

<form method="POST" action="{{ route('settings.branding.reset') }}" data-confirm="Rétablir les couleurs et polices du site matts-couverture.fr ?">
    @csrf
    <div class="form-actions">
        <button class="btn btn-secondary" type="submit">Rétablir les couleurs du site</button>
    </div>
</form>
@endsection
