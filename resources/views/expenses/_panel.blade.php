{{-- Frais d'un chantier : liste, total, ce qu'il reste, et ajout. Visible par le gérant seul. --}}
@php
    use App\Support\Money;
    $assujetti = ! (($job['quote'] ?? $job['invoice'])->isFranchise());
    $rate = $job['invoiced'] > 0 ? (int) round($job['remaining'] * 100 / $job['invoiced']) : null;
@endphp
<ul class="stat-list">
    <li><span>Facturé{{ $assujetti ? ' HT' : '' }}@unless ($job['fully']) <span class="badge badge-warning">en partie · {{ $job['percent'] }} %</span>@endunless</span><strong>{{ Money::format($job['invoiced']) }}</strong></li>
    @unless ($job['fully'])
        <li><span class="muted">Montant du devis{{ $assujetti ? ' HT' : '' }}</span><span class="muted">{{ Money::format($job['planned']) }}</span></li>
    @endunless
    <li><span>Frais du chantier{{ $assujetti ? ' HT' : '' }}</span><strong>− {{ Money::format($job['expenses_total']) }}</strong></li>
    <li class="remaining"><span>Il vous reste</span><strong class="{{ $job['remaining'] < 0 ? 'text-danger' : '' }}">{{ Money::format($job['remaining']) }}@if ($rate !== null) <span class="badge {{ $job['remaining'] < 0 ? 'badge-danger' : 'badge-success' }}">{{ $rate }} %</span>@endif</strong></li>
</ul>

@if ($job['expenses']->isNotEmpty())
    <ul class="stat-list expense-list">
        @foreach ($job['expenses'] as $expense)
            <li>
                <span>{{ $expense->label }}
                    <span class="muted small">· {{ $expense->spent_on->format('d/m/Y') }} · {{ $expense->categoryLabel() }}</span>
                    @if ($expense->receipt_path) · <a class="small" href="{{ str_ends_with(strtolower((string) $expense->receipt_path), '.pdf') ? \App\Http\Controllers\PdfViewerController::link(route('expenses.receipt', $expense), 'Ticket '.$expense->label) : route('expenses.receipt', $expense) }}" @unless (str_ends_with(strtolower((string) $expense->receipt_path), '.pdf')) target="_blank" rel="noopener" @endunless>ticket</a>@endif
                </span>
                <span class="expense-actions">
                    <strong>{{ Money::format($expense->amountHt()) }}</strong>
                    <form method="POST" action="{{ route('expenses.destroy', $expense) }}" data-confirm="Supprimer ce frais ?">
                        @csrf
                        @method('DELETE')
                        <button class="icon-btn" type="submit" aria-label="Supprimer le frais {{ $expense->label }}"><x-icon name="trash" /></button>
                    </form>
                </span>
            </li>
        @endforeach
    </ul>
@else
    <p class="muted small">Aucun frais pour ce chantier.</p>
@endif

<details id="ajouter" @if (($open ?? false) || $errors->hasAny(['expense_label', 'expense_amount', 'expense_vat', 'expense_date', 'receipt'])) open @endif>
    <summary>Ajouter un frais</summary>
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="form-grid cols-2" style="margin-top:.75rem">
        @csrf
        @isset($retour)<input type="hidden" name="retour" value="{{ $retour }}">@endisset
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
        <div class="span-2"><button class="btn" type="submit"><x-icon name="plus" /> Ajouter</button></div>
    </form>
</details>
