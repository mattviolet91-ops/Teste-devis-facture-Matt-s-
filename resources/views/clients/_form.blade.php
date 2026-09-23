@php use App\Models\Client; @endphp
<div class="card">
    <fieldset>
        <legend>Type de client</legend>
        <div class="type-choices" data-client-type>
            @foreach (Client::TYPES as $key => $label)
                <label class="choice">
                    <input type="radio" name="type" value="{{ $key }}" @checked(old('type', $client->type) === $key)>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>
        @error('type')<span class="error">{{ $message }}</span>@enderror
    </fieldset>
</div>

<div class="card">
    <fieldset>
        <legend>Identité</legend>
        <div class="form-grid cols-2">
            <x-field name="company_name" label="Société / organisme" :value="$client->company_name" class="span-2" data-pro-only autocomplete="organization" />
            <x-select name="civility" label="Civilité" :options="array_combine(Client::CIVILITIES, Client::CIVILITIES)" :value="$client->civility" />
            <div data-pro-only class="hint-line"><span class="hint">Pour un professionnel : la personne à contacter.</span></div>
            <x-field name="first_name" label="Prénom" :value="$client->first_name" autocomplete="given-name" />
            <x-field name="last_name" label="Nom" :value="$client->last_name" autocomplete="family-name" />
        </div>
    </fieldset>
</div>

<div class="card">
    <fieldset>
        <legend>Coordonnées</legend>
        <div class="form-grid cols-2">
            <x-field name="phone" label="Téléphone" type="tel" :value="$client->phone" inputmode="tel" autocomplete="tel" />
            <x-field name="phone_2" label="Autre téléphone" type="tel" :value="$client->phone_2" inputmode="tel" />
            <x-field name="email" label="Email" type="email" :value="$client->email" class="span-2" autocomplete="email" />
            <x-field name="address" label="Adresse" :value="$client->address" class="span-2" autocomplete="street-address" />
            <x-field name="postal_code" label="Code postal" :value="$client->postal_code" inputmode="numeric" autocomplete="postal-code" />
            <x-field name="city" label="Ville" :value="$client->city" autocomplete="address-level2" />
            @if (! $client->exists)
                <label class="check span-2">
                    <input type="hidden" name="create_worksite" value="0">
                    <input type="checkbox" name="create_worksite" value="1" @checked(old('create_worksite', true))>
                    <span>Le chantier est à cette adresse<br><span class="muted small">Crée automatiquement le chantier. Décochez si les travaux sont ailleurs.</span></span>
                </label>
            @endif
        </div>
    </fieldset>
</div>

<div class="card">
    <fieldset>
        <legend>Suivi</legend>
        <div class="form-grid cols-2">
            @if ($client->exists)
                <x-select name="status" label="Statut" :options="Client::STATUSES" :value="$client->status" :placeholder="false" hint="Passe automatiquement à « Client » au premier devis accepté." />
            @endif
            <x-select name="source" label="Comment nous a-t-il connus ?" :options="Client::SOURCES" :value="$client->source" />
            <x-field name="source_detail" label="Précision" :value="$client->source_detail" placeholder="ex. recommandé par M. Martin" />
            <x-field name="notes" label="Notes" type="textarea" :value="$client->notes" class="span-2" rows="4" />
        </div>
    </fieldset>
</div>
