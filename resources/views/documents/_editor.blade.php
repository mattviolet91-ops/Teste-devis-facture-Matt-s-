{{--
    Partie commune de l'éditeur de devis et de facture (à placer dans le formulaire) :
    client et chantier, régime de TVA, lignes et totaux.
    Attend : $document, $lines, $clients, $units, $vatRates, $steps, $franchise, $subjectLabel, $subjectPlaceholder.
--}}
@php
    use App\Support\LineInput;

    $partialData = compact('units', 'vatRates', 'steps', 'franchise');
@endphp

<div class="card">
    <fieldset>
        <legend>Client et chantier</legend>
        <div class="form-grid cols-2">
            <div class="field @error('client_id') has-error @enderror">
                <label for="client_id">Client *</label>
                <select id="client_id" name="client_id" required data-client-select>
                    <option value="">— Choisir un client —</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((int) old('client_id', $document->client_id) === $client->id)>{{ $client->displayName() }}{{ $client->city ? ' — '.$client->city : '' }}</option>
                    @endforeach
                </select>
                <span class="hint"><a href="{{ route('clients.create') }}">Créer un nouveau client</a></span>
            </div>
            <div class="field @error('worksite_id') has-error @enderror">
                <label for="worksite_id">Chantier</label>
                <select id="worksite_id" name="worksite_id" data-worksite-select data-selected="{{ old('worksite_id', $document->worksite_id) }}">
                    <option value="">— Aucun —</option>
                </select>
            </div>
            <x-field name="title" :label="$subjectLabel" :value="$document->title" class="span-2" :placeholder="$subjectPlaceholder" />
        </div>
    </fieldset>
</div>

<div class="card">
    <div class="card-head">
        <h2>Prestations</h2>
    </div>
    <div class="field vat-regime">
        <label for="vat_regime">TVA de ce document</label>
        <select id="vat_regime" name="vat_regime" data-vat-regime>
            <option value="franchise" @selected($franchise)>Sans TVA — TVA non applicable (art. 293 B du CGI)</option>
            <option value="assujetti" @selected(! $franchise)>Avec TVA (taux au choix sur chaque ligne)</option>
        </select>
        <span class="hint">Par défaut : le régime choisi dans Réglages → TVA. Modifiable sur chaque document.</span>
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
                        <option value="percent" @selected(old('discount_type', $document->discount_type) === 'percent')>en %</option>
                        <option value="amount" @selected(old('discount_type', $document->discount_type) === 'amount')>en €</option>
                    </select>
                    <input type="text" inputmode="decimal" name="discount_value" aria-label="Valeur de la remise" data-calc
                        value="{{ old('discount_value', $document->discount_type === 'percent' ? \App\Support\Percent::input((int) $document->discount_value) : ($document->discount_type === 'amount' ? LineInput::money((int) $document->discount_value) : '')) }}">
                </span>
            </dt>
            <dd data-total="discount">—</dd>
        </div>
        <div class="strong"><dt>Total HT</dt><dd data-total="total_ht">—</dd></div>
        <div data-vat-rows></div>
        <div class="grand"><dt data-total-label>{{ $franchise ? 'Total' : 'Total TTC' }}</dt><dd data-total="total_ttc">—</dd></div>
        <div class="muted" data-optional-row hidden><dt>Options proposées (hors total)</dt><dd data-total="optional_total">—</dd></div>
    </dl>
    <p class="small muted" data-franchise-mention @unless ($franchise) hidden @endunless>{{ $settings->get('vat.franchise_mention') }}</p>
</div>
