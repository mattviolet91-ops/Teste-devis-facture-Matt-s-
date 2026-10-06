{{-- Champs d'un frais (description, montant, TVA, type, date, ticket). --}}
<x-field name="expense_label" label="Description" required placeholder="ex. Tuiles, location échafaudage, déchetterie" />
<x-field name="expense_amount" :label="$assujetti ? 'Montant payé TTC (€)' : 'Montant (€)'" required inputmode="decimal" placeholder="0,00" />
@if ($assujetti)
    <x-field name="expense_vat" label="Dont TVA (€)" inputmode="decimal" placeholder="0,00" hint="La TVA récupérable n'est pas déduite." />
@endif
<div class="field @error('category') has-error @enderror">
    <label for="category">Type</label>
    <select id="category" name="category">
        @foreach (\App\Models\Expense::CATEGORIES as $key => $label)
            <option value="{{ $key }}" @selected(old('category', 'materiaux') === $key)>{{ $label }}</option>
        @endforeach
    </select>
</div>
<x-field name="expense_date" label="Date" type="date" :value="today()->toDateString()" />
<div class="field @error('receipt') has-error @enderror">
    <label for="receipt">Photo du ticket <span class="muted small">(facultatif)</span></label>
    <input id="receipt" type="file" name="receipt" accept="image/*,application/pdf">
    @error('receipt')<span class="error">{{ $message }}</span>@enderror
</div>
