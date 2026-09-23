{{-- Conditions de paiement, notes visibles et notes internes (dans une grille à 2 colonnes). --}}
<div class="field span-2">
    <label for="payment_terms">Conditions de paiement</label>
    <select class="template-picker" data-template-target="payment_terms" data-template-mode="replace" aria-label="Choisir des conditions de paiement">
        <option value="">Choisir un modèle…</option>
        @foreach ($paymentTemplates as $template)<option value="{{ $template->body }}">{{ $template->label }}</option>@endforeach
    </select>
    <textarea id="payment_terms" name="payment_terms" rows="2" data-autogrow>{{ old('payment_terms', $document->payment_terms) }}</textarea>
</div>

<div class="field span-2">
    <label for="notes">Notes et informations (visibles par le client)</label>
    <select class="template-picker" data-template-target="notes" data-template-mode="append" aria-label="Ajouter un texte type">
        <option value="">+ Ajouter un texte type…</option>
        @foreach ($noteTemplates as $template)<option value="{{ $template->body }}">{{ $template->label }}</option>@endforeach
    </select>
    <textarea id="notes" name="notes" rows="3" data-autogrow>{{ old('notes', $document->notes) }}</textarea>
</div>

<x-field name="internal_notes" label="Notes internes (jamais visibles par le client)" type="textarea" :value="$document->internal_notes" class="span-2" rows="2" />
