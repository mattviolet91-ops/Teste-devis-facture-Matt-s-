@extends('layouts.app', ['title' => $quote->exists ? 'Modifier le devis' : 'Nouveau devis'])

@php
    use App\Support\LineInput;

    $lines = old('lines') !== null
        ? collect(old('lines'))->map(fn ($l) => array_merge(LineInput::blank($l['type'] ?? 'item', $defaultVatRate), $l))->values()
        : $quote->lines->map(fn ($line) => LineInput::fromModel($line))->values();

    $clientData = $clients->mapWithKeys(fn ($c) => [$c->id => $c->worksites->map(fn ($w) => ['id' => $w->id, 'label' => $w->label ? $w->label.' — '.$w->fullAddress() : $w->fullAddress()])->values()]);
    $partialData = compact('units', 'vatRates', 'steps', 'franchise');
    $editorData = ['catalog' => $catalog, 'franchise' => $franchise, 'defaultVatRate' => $defaultVatRate, 'worksites' => $clientData];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $quote->exists ? 'Modifier le brouillon' : 'Nouveau devis' }}</h1>
            @if ($quote->replaces)
                <p>Nouvelle version du devis {{ $quote->replaces->number }} : un nouveau numéro sera attribué à l'envoi.</p>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <ul class="error-list">
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $quote->exists ? route('quotes.update', $quote) : route('quotes.store') }}" id="quote-form" data-quote-editor novalidate>
        @csrf
        @if ($quote->exists) @method('PUT') @endif

        <div class="card">
            <fieldset>
                <legend>Client et chantier</legend>
                <div class="form-grid cols-2">
                    <div class="field @error('client_id') has-error @enderror">
                        <label for="client_id">Client *</label>
                        <select id="client_id" name="client_id" required data-client-select>
                            <option value="">— Choisir un client —</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected((int) old('client_id', $quote->client_id) === $client->id)>{{ $client->displayName() }}{{ $client->city ? ' — '.$client->city : '' }}</option>
                            @endforeach
                        </select>
                        <span class="hint"><a href="{{ route('clients.create') }}">Créer un nouveau client</a></span>
                    </div>
                    <div class="field @error('worksite_id') has-error @enderror">
                        <label for="worksite_id">Chantier</label>
                        <select id="worksite_id" name="worksite_id" data-worksite-select data-selected="{{ old('worksite_id', $quote->worksite_id) }}">
                            <option value="">— Aucun —</option>
                        </select>
                    </div>
                    <x-field name="title" label="Objet du devis" :value="$quote->title" class="span-2" placeholder="ex. Traitement de toiture et remplacement de faîtières" />
                </div>
            </fieldset>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Prestations</h2>
                @if ($franchise)<span class="badge">TVA non applicable</span>@endif
            </div>
            <div class="lines" data-lines>
                @foreach ($lines as $i => $l)
                    @include('quotes._line', ['i' => $i, 'l' => $l] + $partialData)
                @endforeach
            </div>
            <p class="muted small empty-lines" data-empty-lines @if ($lines->isNotEmpty()) hidden @endif>Aucune ligne. Ajoutez une prestation depuis la bibliothèque ou une ligne libre.</p>
            <div class="add-buttons">
                <button type="button" class="btn" data-open-catalog><x-icon name="book" /> Bibliothèque</button>
                <button type="button" class="btn btn-secondary" data-add="item"><x-icon name="plus" /> Ligne libre</button>
                <button type="button" class="btn btn-secondary" data-add="section"><x-icon name="plus" /> Section</button>
                <button type="button" class="btn btn-secondary" data-add="text"><x-icon name="plus" /> Texte</button>
            </div>
        </div>

        <div class="card totals-card">
            <h2>Totaux</h2>
            <dl class="totals" data-totals>
                <div><dt>Sous-total HT</dt><dd data-total="subtotal">—</dd></div>
                <div class="discount-row">
                    <dt>
                        <label for="discount_type">Remise globale</label>
                        <span class="discount-inputs">
                            <select id="discount_type" name="discount_type" data-calc aria-label="Type de remise">
                                <option value="">Aucune</option>
                                <option value="percent" @selected(old('discount_type', $quote->discount_type) === 'percent')>en %</option>
                                <option value="amount" @selected(old('discount_type', $quote->discount_type) === 'amount')>en €</option>
                            </select>
                            <input type="text" inputmode="decimal" name="discount_value" aria-label="Valeur de la remise" data-calc
                                value="{{ old('discount_value', $quote->discount_type === 'percent' ? \App\Support\Percent::input((int) $quote->discount_value) : ($quote->discount_type === 'amount' ? LineInput::money((int) $quote->discount_value) : '')) }}">
                        </span>
                    </dt>
                    <dd data-total="discount">—</dd>
                </div>
                <div class="strong"><dt>Total HT</dt><dd data-total="total_ht">—</dd></div>
                <div data-vat-rows></div>
                <div class="grand"><dt>{{ $franchise ? 'Total' : 'Total TTC' }}</dt><dd data-total="total_ttc">—</dd></div>
                <div class="muted" data-optional-row hidden><dt>Options proposées (hors total)</dt><dd data-total="optional_total">—</dd></div>
            </dl>
            @if ($franchise)
                <p class="small muted">{{ $settings->get('vat.franchise_mention') }}</p>
            @endif
        </div>

        <div class="card">
            <fieldset>
                <legend>Conditions et informations</legend>
                <div class="form-grid cols-2">
                    <x-field name="validity_days" label="Validité (jours)" type="number" min="1" max="365" :value="$quote->validity_days" required />
                    <x-field name="work_start" label="Date prévue des travaux" :value="$quote->work_start" placeholder="ex. semaine 42, sous 10 à 15 jours" />
                    <x-field name="work_duration" label="Durée estimée" :value="$quote->work_duration" placeholder="ex. 2 jours" class="span-2" />

                    <div class="field span-2">
                        <label for="payment_terms">Conditions de paiement</label>
                        <select class="template-picker" data-template-target="payment_terms" data-template-mode="replace" aria-label="Choisir des conditions de paiement">
                            <option value="">Choisir un modèle…</option>
                            @foreach ($paymentTemplates as $template)<option value="{{ $template->body }}">{{ $template->label }}</option>@endforeach
                        </select>
                        <textarea id="payment_terms" name="payment_terms" rows="2" data-autogrow>{{ old('payment_terms', $quote->payment_terms) }}</textarea>
                    </div>

                    <div class="field span-2">
                        <label for="notes">Notes et informations (visibles par le client)</label>
                        <select class="template-picker" data-template-target="notes" data-template-mode="append" aria-label="Ajouter un texte type">
                            <option value="">+ Ajouter un texte type…</option>
                            @foreach ($noteTemplates as $template)<option value="{{ $template->body }}">{{ $template->label }}</option>@endforeach
                        </select>
                        <textarea id="notes" name="notes" rows="3" data-autogrow>{{ old('notes', $quote->notes) }}</textarea>
                    </div>

                    <x-field name="internal_notes" label="Notes internes (jamais visibles par le client)" type="textarea" :value="$quote->internal_notes" class="span-2" rows="2" />
                </div>
            </fieldset>
        </div>

        <div class="form-actions sticky-actions">
            <button class="btn" type="submit">Enregistrer le brouillon</button>
            <a class="btn btn-secondary" href="{{ $quote->exists ? route('quotes.show', $quote) : route('quotes.index') }}">Annuler</a>
        </div>
    </form>

    {{-- Modèles de lignes clonés par le script --}}
    @foreach (['item', 'section', 'text'] as $type)
        <template id="tpl-line-{{ $type }}">
            @include('quotes._line', ['i' => '__i__', 'l' => LineInput::blank($type, $defaultVatRate)] + $partialData)
        </template>
    @endforeach

    <dialog class="sheet sheet-tall" id="catalog-dialog" aria-labelledby="catalog-title">
        <div class="card-head">
            <h2 id="catalog-title">Bibliothèque de prestations</h2>
            <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
        </div>
        <label class="search-field" for="catalog-search">
            <x-icon name="search" /><span class="visually-hidden">Rechercher une prestation</span>
            <input id="catalog-search" type="search" placeholder="Rechercher : démoussage, faîtière, Velux…" autocomplete="off">
        </label>
        <div class="catalog-list" data-catalog-list></div>
        <p class="small muted" style="margin-top:.75rem"><a href="{{ route('catalog.index') }}">Gérer la bibliothèque</a></p>
    </dialog>

    <script type="application/json" id="quote-editor-data">@json($editorData)</script>
    <script src="{{ asset('js/quote-editor.js') }}?v={{ filemtime(public_path('js/quote-editor.js')) }}" defer></script>
@endsection
