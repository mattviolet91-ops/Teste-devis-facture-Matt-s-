@extends('layouts.app', ['title' => $worksite->exists ? 'Modifier le chantier' : 'Nouveau chantier'])

@php use App\Models\Worksite; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $worksite->exists ? 'Modifier le chantier' : 'Nouveau chantier' }}</h1>
            <p><a href="{{ route('clients.show', $client) }}">{{ $client->displayName() }}</a></p>
        </div>
    </div>

    <form method="POST" action="{{ $worksite->exists ? route('worksites.update', $worksite) : route('worksites.store', $client) }}" data-offline="Chantier">
        @csrf
        @if ($worksite->exists) @method('PUT') @endif

        <div class="card">
            <fieldset>
                <legend>Adresse des travaux</legend>
                @if (! $worksite->exists && $client->fullAddress())
                    <button type="button" class="btn btn-secondary btn-sm" style="margin-bottom:1rem"
                        data-fill-address='@json($client->only('address', 'postal_code', 'city'))'>Reprendre l'adresse du client</button>
                @endif
                <div class="form-grid cols-2">
                    <x-field name="label" label="Nom du chantier (facultatif)" :value="$worksite->label" class="span-2" placeholder="ex. Maison principale, Résidence Les Tilleuls bât. B" />
                    <x-field name="address" label="Adresse" :value="$worksite->address" class="span-2" autocomplete="off" data-address-autocomplete placeholder="Commencez à taper : 12 rue des…" required />
                    <x-field name="postal_code" label="Code postal" :value="$worksite->postal_code" inputmode="numeric" required />
                    <x-field name="city" label="Ville" :value="$worksite->city" required />
                </div>
            </fieldset>
        </div>

        <div class="card">
            <fieldset>
                <legend>Sur place</legend>
                <div class="form-grid cols-2">
                    <x-field name="contact_name" label="Contact sur place" :value="$worksite->contact_name" placeholder="ex. gardien, locataire" />
                    <x-field name="contact_phone" label="Téléphone du contact" type="tel" :value="$worksite->contact_phone" inputmode="tel" />
                    <x-field name="access_notes" label="Accès" type="textarea" :value="$worksite->access_notes" class="span-2" rows="3" placeholder="Digicode, clés, stationnement, animal, horaires…" />
                </div>
            </fieldset>
        </div>

        <div class="card">
            <fieldset>
                <legend>Toiture</legend>
                <div class="form-grid cols-2">
                    <x-select name="roof_type" label="Type de couverture" :options="Worksite::ROOF_TYPES" :value="$worksite->roof_type" />
                    <x-field name="roof_surface" label="Surface (m²)" :value="$worksite->roof_surface !== null ? rtrim(rtrim(str_replace('.', ',', (string) $worksite->roof_surface), '0'), ',') : null" inputmode="decimal" />
                    <x-field name="roof_pitch" label="Pente" :value="$worksite->roof_pitch" placeholder="ex. 35°, forte" />
                    <x-field name="levels" label="Nombre de niveaux" type="number" min="0" max="50" :value="$worksite->levels" />
                    <x-select name="accessibility" label="Accès à la toiture" :options="Worksite::ACCESSIBILITY" :value="$worksite->accessibility" class="span-2" />
                    <x-field name="notes" label="Notes" type="textarea" :value="$worksite->notes" class="span-2" rows="3" />
                </div>
            </fieldset>
        </div>

        <div class="form-actions sticky-actions">
            <button class="btn" type="submit">Enregistrer le chantier</button>
            <a class="btn btn-secondary" href="{{ route('clients.show', $client) }}">Annuler</a>
        </div>
    </form>

    @if ($worksite->exists)
        <form method="POST" action="{{ route('worksites.destroy', $worksite) }}" data-confirm="Mettre ce chantier à la corbeille ?">
            @csrf
            @method('DELETE')
            <div class="form-actions">
                <button class="btn btn-danger-outline" type="submit">Mettre à la corbeille</button>
            </div>
        </form>
    @endif
@endsection
