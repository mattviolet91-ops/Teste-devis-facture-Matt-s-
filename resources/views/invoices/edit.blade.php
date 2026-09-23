@extends('layouts.app', ['title' => $invoice->exists ? 'Modifier la facture' : 'Nouvelle facture'])

@php
    $lines = \App\Support\LineInput::forEditor($invoice, $defaultVatRate);
    $editor = compact('lines', 'clients', 'units', 'vatRates', 'steps', 'franchise', 'catalog', 'defaultVatRate')
        + ['document' => $invoice, 'subjectLabel' => 'Objet de la facture', 'subjectPlaceholder' => 'ex. Traitement de toiture'];
    $dueDays = (int) old('due_days', $invoice->due_days);
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $invoice->exists ? 'Modifier le brouillon' : 'Nouvelle facture' }}</h1>
            <p>
                {{ $invoice->kindLabel() }}
                @if ($invoice->quote) — devis <a href="{{ route('quotes.show', $invoice->quote) }}">{{ $invoice->quote->number }}</a>@endif
                @if ($invoice->corrects) — corrige la facture <a href="{{ route('invoices.show', $invoice->corrects) }}">{{ $invoice->corrects->number }}</a>@endif
            </p>
        </div>
    </div>

    <p class="muted small">Le numéro de facture (FAC-…) est attribué au moment de l'envoi.</p>

    @include('documents._errors')

    <form method="POST" action="{{ $invoice->exists ? route('invoices.update', $invoice) : route('invoices.store') }}" id="invoice-form" data-quote-editor novalidate>
        @csrf
        @if ($invoice->exists) @method('PUT') @endif

        @include('documents._editor', $editor)

        <div class="card">
            <fieldset>
                <legend>Conditions et informations</legend>
                <div class="form-grid cols-2">
                    <div class="field @error('due_days') has-error @enderror">
                        <label for="due_days">Échéance</label>
                        <select id="due_days" name="due_days">
                            @foreach ([0 => 'À réception', 7 => '7 jours', 15 => '15 jours', 30 => '30 jours', 45 => '45 jours'] as $days => $label)
                                <option value="{{ $days }}" @selected($dueDays === $days)>{{ $label }}</option>
                            @endforeach
                            @unless (in_array($dueDays, [0, 7, 15, 30, 45], true))
                                <option value="{{ $dueDays }}" selected>{{ $dueDays }} jours</option>
                            @endunless
                        </select>
                        <span class="hint">Date limite de paiement calculée à l'envoi.</span>
                    </div>
                    <x-field name="work_period" label="Date des travaux" :value="$invoice->work_period" placeholder="ex. du 12 au 14 octobre 2026" hint="Mention obligatoire sur la facture." />
                    @include('documents._bank', ['document' => $invoice])
                    @include('documents._texts', ['document' => $invoice])
                </div>
            </fieldset>
        </div>

        <div class="form-actions sticky-actions">
            <button class="btn" type="submit">Enregistrer le brouillon</button>
            <a class="btn btn-secondary" href="{{ $invoice->exists ? route('invoices.show', $invoice) : route('invoices.index') }}">Annuler</a>
        </div>
    </form>

    @include('documents._editor-assets', $editor)
@endsection
