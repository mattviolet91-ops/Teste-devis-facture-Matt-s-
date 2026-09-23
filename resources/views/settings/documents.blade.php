@extends('settings.layout', ['title' => 'Documents PDF'])

@section('settings')
<form method="POST" action="{{ route('settings.documents') }}">
    @csrf
    @method('PUT')

    <p class="muted small">Ces informations sont imprimées sur les devis et les factures. Un document déjà envoyé garde le PDF de son envoi : les modifications ne s'appliquent qu'aux documents suivants.</p>

    @if (! $mediator)
        <div class="alert alert-warning">Aucun médiateur de la consommation n'est renseigné. C'est obligatoire pour travailler avec des particuliers : ajoutez-le dans <a href="{{ route('settings.company') }}">Réglages → Entreprise</a> dès votre adhésion.</div>
    @endif

    <div class="card">
        <h2>Page de couverture</h2>
        <p class="muted small">Une première page à vos couleurs : logo, type de document, client, encadré d'assurance décennale et coordonnées.</p>
        <label class="check"><input type="checkbox" name="pdf[cover_quotes]" value="1" @checked(old('pdf.cover_quotes', $pdf['cover_quotes']))> <span>Sur les devis</span></label>
        <label class="check" style="margin-top:.5rem"><input type="checkbox" name="pdf[cover_invoices]" value="1" @checked(old('pdf.cover_invoices', $pdf['cover_invoices']))> <span>Sur les factures et avoirs</span></label>
        <div class="form-grid" style="margin-top:.75rem">
            <x-field name="pdf.presentation_text" label="Texte de présentation (facultatif)" type="textarea" rows="4" :value="$pdf['presentation_text']"
                placeholder="ex. Couvreur dans l'Essonne, nous intervenons pour l'entretien, la réparation et la rénovation de toitures…" hint="Imprimé sur la page de couverture." />
        </div>
    </div>

    <div class="card">
        <h2>Assurance décennale</h2>
        <p class="muted small">Mention obligatoire sur les devis et les factures. L'attestation PDF et les rappels d'échéance arriveront avec la phase 9.</p>
        <div class="form-grid cols-2">
            <x-field name="insurance.insurer" label="Assureur" :value="$insurance['insurer']" required />
            <x-field name="insurance.broker" label="Courtier" :value="$insurance['broker']" />
            <x-field name="insurance.insurer_address" label="Adresse de l'assureur" :value="$insurance['insurer_address']" class="span-2" />
            <x-field name="insurance.policy_number" label="N° de contrat" :value="$insurance['policy_number']" required />
            <x-field name="insurance.coverage_area" label="Couverture géographique" :value="$insurance['coverage_area']" required />
            <x-field name="insurance.valid_from" label="Valable du" type="date" :value="$insurance['valid_from']" required />
            <x-field name="insurance.valid_until" label="Au" type="date" :value="$insurance['valid_until']" required />
            <x-field name="insurance.activities" label="Activités couvertes" :value="$insurance['activities']" class="span-2" required />
        </div>
    </div>

    <div class="card">
        <h2>Gestion des déchets (devis)</h2>
        <p class="muted small">Obligatoire sur les devis de travaux : modalités d'enlèvement, installation de collecte et coût. L'estimation des quantités se saisit sur chaque devis.</p>
        <div class="form-grid cols-2">
            <x-field name="pdf.waste_mention" label="Texte" type="textarea" rows="3" :value="$pdf['waste_mention']" class="span-2" required />
            <x-field name="pdf.waste_facility" label="Installation de collecte (nom, adresse, type)" :value="$pdf['waste_facility']" class="span-2"
                placeholder="ex. Déchetterie professionnelle …, adresse…" hint="À compléter : là où vous déposez les déchets de chantier." />
        </div>
    </div>

    <div class="card">
        <h2>Annexes des devis</h2>
        <label class="check"><input type="checkbox" name="pdf[cgv_enabled]" value="1" @checked(old('pdf.cgv_enabled', $pdf['cgv_enabled']))>
            <span>Joindre les conditions générales de vente</span></label>
        <div class="form-grid" style="margin-top:.75rem">
            <x-field name="pdf.cgv" label="Conditions générales de vente" type="textarea" rows="12" :value="$pdf['cgv']" hint="Un paragraphe par ligne. Texte de départ à faire relire." />
        </div>

    </div>

    <div class="form-actions">
        <button class="btn" type="submit">Enregistrer</button>
    </div>
</form>
@endsection
