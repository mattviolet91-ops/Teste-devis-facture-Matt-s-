@extends('settings.layout', ['title' => 'Entreprise'])

@section('settings')
<form method="POST" action="{{ route('settings.company') }}">
    @csrf
    @method('PUT')

    <div class="card">
        <fieldset>
            <legend>Identité</legend>
            <div class="form-grid cols-2">
                <x-field name="company.trade_name" label="Nom commercial" :value="$company['trade_name']" required />
                <x-field name="company.slogan" label="Slogan" :value="$company['slogan']" />
                <x-field name="company.owner_name" label="Nom de l'entrepreneur" :value="$company['owner_name']" required />
                <x-field name="company.legal_form" label="Forme juridique" :value="$company['legal_form']" hint="« EI » est obligatoire sur les documents d'un entrepreneur individuel." required />
                <x-field name="company.agreements" label="Agréments / qualifications" :value="$company['agreements']" class="span-2" />
            </div>
        </fieldset>
    </div>

    <div class="card">
        <fieldset>
            <legend>Coordonnées</legend>
            <div class="form-grid cols-2">
                <x-field name="company.address" label="Adresse" :value="$company['address']" class="span-2" autocomplete="street-address" required />
                <x-field name="company.postal_code" label="Code postal" :value="$company['postal_code']" inputmode="numeric" autocomplete="postal-code" required />
                <x-field name="company.city" label="Ville" :value="$company['city']" autocomplete="address-level2" required />
                <x-field name="company.phone" label="Téléphone" type="tel" :value="$company['phone']" required />
                <x-field name="company.email" label="Email" type="email" :value="$company['email']" required />
                <x-field name="company.website" label="Site internet" type="url" :value="$company['website']" class="span-2" />
            </div>
        </fieldset>
    </div>

    <div class="card">
        <fieldset>
            <legend>Informations légales</legend>
            <div class="form-grid cols-2">
                <x-field name="company.siret" label="SIRET" :value="$company['siret']" inputmode="numeric" required />
                <x-field name="company.ape_code" label="Code APE" :value="$company['ape_code']" />
                <x-field name="company.vat_number" label="N° de TVA intracommunautaire" :value="$company['vat_number']" hint="Laisser vide si vous n'en avez pas." />
                <div></div>
                <x-field name="company.mediator_name" label="Médiateur de la consommation" :value="$company['mediator_name']" hint="Obligatoire pour les clients particuliers." />
                <x-field name="company.mediator_url" label="Site du médiateur" type="url" :value="$company['mediator_url']" />
            </div>
        </fieldset>
    </div>

    <div class="card">
        <fieldset>
            <legend>Coordonnées bancaires</legend>
            <div class="form-grid cols-2">
                <x-field name="bank.holder" label="Titulaire du compte" :value="$bank['holder']" />
                <x-field name="bank.bic" label="BIC" :value="$bank['bic']" />
                <x-field name="bank.iban" label="IBAN" :value="$bank['iban']" class="span-2" />
                <label class="check span-2">
                    <input type="checkbox" name="bank[show_by_default]" value="1" @checked(old('bank.show_by_default', $bank['show_by_default']))>
                    <span>Afficher l'IBAN par défaut sur les nouvelles factures<br><span class="muted small">Modifiable sur chaque facture.</span></span>
                </label>
            </div>
        </fieldset>
    </div>

    <div class="form-actions">
        <button class="btn" type="submit">Enregistrer</button>
    </div>
</form>
@endsection
