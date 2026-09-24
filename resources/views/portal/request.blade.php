@extends('layouts.portal', ['title' => 'Demande de devis'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Demande de devis gratuit</h1>
            <p>Décrivez votre projet : nous vous rappelons rapidement pour convenir d'une visite.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert"><ul class="error-list">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('portal.request.store') }}" enctype="multipart/form-data" class="card">
        @csrf
        <input type="hidden" name="started" value="{{ $started }}">
        {{-- Piège à robots : champ invisible pour les humains. --}}
        <div class="visually-hidden" aria-hidden="true"><label>Ne pas remplir <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <fieldset>
            <legend>Vos coordonnées</legend>
            <div class="form-grid cols-2">
                <x-select name="civility" label="Civilité" :options="array_combine(\App\Models\Client::CIVILITIES, \App\Models\Client::CIVILITIES)" placeholder="—" />
                <x-field name="last_name" label="Nom" autocomplete="family-name" required />
                <x-field name="first_name" label="Prénom" autocomplete="given-name" />
                <x-field name="phone" label="Téléphone" type="tel" inputmode="tel" autocomplete="tel" required />
                <x-field name="email" label="Email" type="email" autocomplete="email" class="span-2" />
            </div>
        </fieldset>

        <fieldset>
            <legend>Adresse des travaux</legend>
            <div class="form-grid cols-2">
                <x-field name="address" label="Adresse" autocomplete="street-address" class="span-2" />
                <x-field name="postal_code" label="Code postal" inputmode="numeric" autocomplete="postal-code" />
                <x-field name="city" label="Ville" autocomplete="address-level2" />
            </div>
        </fieldset>

        <fieldset>
            <legend>Vos travaux</legend>
            <div class="request-works">
                @foreach (\App\Models\QuoteRequest::WORKS as $key => $label)
                    <label class="check"><input type="checkbox" name="works[]" value="{{ $key }}" @checked(in_array($key, old('works', []), true))> <span>{{ $label }}</span></label>
                @endforeach
            </div>
            @error('works')<span class="error">{{ $message }}</span>@enderror
            <x-field name="message" label="Précisions (surface, urgence, fuite…)" type="textarea" rows="4" />
            <div class="field">
                <label for="photos">Photos de la toiture (facultatif, 5 maximum)</label>
                <input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
            </div>
            <x-field name="availability" label="Vos disponibilités pour une visite" placeholder="ex. en semaine après 17 h, le samedi matin" />
        </fieldset>

        <label class="check"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
            <span>J'accepte d'être recontacté au sujet de ma demande. Mes informations ne sont utilisées que pour établir le devis.</span></label>

        <div class="form-actions"><button class="btn" type="submit"><x-icon name="send" /> Envoyer ma demande</button></div>
    </form>
@endsection
