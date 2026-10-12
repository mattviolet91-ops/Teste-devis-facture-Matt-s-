@extends('layouts.app', ['title' => $quote->exists ? 'Modifier le devis' : 'Nouveau devis'])

@php
    $lines = \App\Support\LineInput::forEditor($quote, $defaultVatRate);
    $editor = compact('lines', 'clients', 'units', 'vatRates', 'steps', 'franchise', 'catalog', 'defaultVatRate')
        + ['document' => $quote, 'subjectLabel' => 'Objet du devis', 'subjectPlaceholder' => 'ex. Traitement de toiture et remplacement de faîtières'];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $quote->exists ? 'Modifier le brouillon' : 'Nouveau devis' }}</h1>
            @unless ($quote->exists)
                <p>Plus rapide : <a href="{{ route('quotes.express') }}">Devis express</a>, le devis en une phrase.</p>
            @endunless
            @if ($quote->replaces)
                <p>Modification du devis {{ $quote->replaces->number }} : à l'envoi, il deviendra {{ app(\App\Services\QuoteService::class)->versionNumber($quote->replaces->number) }} et remplacera l'ancien.</p>
            @endif
        </div>
    </div>

    @include('documents._errors')

    <form method="POST" action="{{ $quote->exists ? route('quotes.update', $quote) : route('quotes.store') }}" id="quote-form" data-quote-editor data-offline="Devis" novalidate>
        @csrf
        @if ($quote->exists) @method('PUT') @endif

        @include('documents._editor', $editor)

        <div class="card">
            <fieldset>
                <legend>Conditions et informations</legend>
                <div class="form-grid cols-2">
                    <x-field name="validity_days" label="Validité (jours)" type="number" min="1" max="365" :value="$quote->validity_days" required />
                    <x-field name="work_start" label="Date prévue des travaux" :value="$quote->work_start" placeholder="ex. semaine 42, sous 10 à 15 jours" />
                    <x-field name="work_duration" label="Durée estimée" :value="$quote->work_duration" placeholder="ex. 2 jours" />
                    <x-field name="waste_estimate" label="Déchets estimés" :value="$quote->waste_estimate" placeholder="ex. environ 2 m³ de tuiles et gravats" hint="Imprimé dans la mention sur les déchets." />
                    @include('documents._bank', ['document' => $quote])
                    <div class="field span-2">
                        <label class="check"><input type="checkbox" name="urgent_repair" value="1" @checked(session()->hasOldInput() ? old('urgent_repair') : $quote->urgent_repair)>
                            <span>Réparation urgente demandée par le client (fuite, tuiles envolées…)</span></label>
                        <span class="hint">Pour une urgence au domicile d'un particulier : paiement possible sans attendre 7 jours, et pas de délai de rétractation pour les travaux strictement nécessaires. La mention est imprimée sur le devis. Sans urgence, un devis signé chez le client ne peut être payé qu'à partir du 8e jour.</span>
                    </div>
                    @include('documents._texts', ['document' => $quote])
                </div>
            </fieldset>
        </div>

        <div class="form-actions sticky-actions">
            <button class="btn" type="submit">Enregistrer le brouillon</button>
            <a class="btn btn-secondary" href="{{ $quote->exists ? route('quotes.show', $quote) : route('quotes.index') }}">Annuler</a>
        </div>
    </form>

    @include('documents._editor-assets', $editor)
@endsection
