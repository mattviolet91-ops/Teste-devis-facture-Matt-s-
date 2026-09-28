@extends('layouts.app', ['title' => $expense->exists ? 'Modifier l\'achat' : 'Nouvel achat'])

@php
    use App\Support\Money;
    $assujetti = app(\App\Services\Settings::class)->get('vat.regime') === 'assujetti';
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $expense->exists ? 'Modifier l\'achat' : 'Nouvel achat' }}</h1>
            <p>Matériaux, location, déchetterie… pour connaître la marge de chaque chantier.</p>
        </div>
    </div>

    <form method="POST" action="{{ $expense->exists ? route('expenses.update', $expense) : route('expenses.store') }}" enctype="multipart/form-data" class="card">
        @csrf
        @if ($expense->exists) @method('PUT') @endif
        <div class="form-grid cols-2">
            <div class="field span-2 @error('receipt') has-error @enderror">
                <label for="receipt">Photo du ticket ou de la facture <span class="muted small">(facultatif)</span></label>
                <input id="receipt" type="file" name="receipt" accept="image/*,application/pdf">
                @if ($expense->receipt_path)<span class="hint">Ticket déjà joint : <a href="{{ route('expenses.receipt', $expense) }}" target="_blank" rel="noopener">voir</a>. Une nouvelle photo le remplace.</span>@endif
                @error('receipt')<span class="error">{{ $message }}</span>@enderror
            </div>
            <x-field name="label" label="Description" :value="$expense->label" required placeholder="ex. Tuiles, liteaux, zinc" />
            <x-field name="supplier" label="Fournisseur" :value="$expense->supplier" placeholder="ex. Point.P, Tout Faire Matériaux" />
            <x-field name="amount" :label="$assujetti ? 'Montant payé TTC (€)' : 'Montant payé (€)'" :value="$expense->exists ? number_format($expense->amount_ttc / 100, 2, ',', '') : ''" required inputmode="decimal" placeholder="0,00" />
            @if ($assujetti)
                <x-field name="vat" label="Dont TVA (€)" :value="$expense->exists ? number_format($expense->vat / 100, 2, ',', '') : ''" inputmode="decimal" placeholder="0,00" hint="La TVA récupérable n'est pas comptée dans le coût du chantier." />
            @endif
            <x-field name="spent_on" label="Date" type="date" :value="$expense->spent_on?->toDateString()" required />
            <x-select name="category" label="Catégorie" :options="\App\Models\Expense::CATEGORIES" :value="$expense->category" :placeholder="false" />
            <div class="field span-2 @error('quote_id') has-error @enderror">
                <label for="quote_id">Chantier</label>
                <select id="quote_id" name="quote_id">
                    <option value="">Aucun (frais général)</option>
                    @foreach ($quotes as $quote)
                        <option value="{{ $quote->id }}" @selected((string) old('quote_id', $expense->quote_id) === (string) $quote->id)>{{ $quote->number }} · {{ $quote->client?->displayName() }}{{ $quote->title ? ' — '.\Illuminate\Support\Str::limit($quote->title, 40) : '' }}</option>
                    @endforeach
                </select>
                <span class="hint">Les devis acceptés. L'achat est déduit de la marge de ce chantier.</span>
                @error('quote_id')<span class="error">{{ $message }}</span>@enderror
            </div>
        </div>
        <div class="action-bar">
            <button class="btn" type="submit"><x-icon name="check" /> Enregistrer</button>
            <a class="btn btn-secondary" href="{{ $expense->quote_id ? route('quotes.show', $expense->quote_id) : route('expenses.index') }}">Annuler</a>
        </div>
    </form>

    @if ($expense->exists)
        <form method="POST" action="{{ route('expenses.destroy', $expense) }}" data-confirm="Supprimer cet achat ?">
            @csrf
            @method('DELETE')
            <button class="btn btn-secondary" type="submit"><x-icon name="trash" /> Supprimer l'achat</button>
        </form>
    @endif
@endsection
