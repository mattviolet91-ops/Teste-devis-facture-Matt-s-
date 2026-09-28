{{-- Frais de la facture : visibles par le gérant seul (jamais sur le PDF ni le lien client). --}}
@php
    use App\Support\Money;
    $expenses = $invoice->expenses;
    $totalExpenses = $expenses->sum(fn ($e) => $e->amountHt());
    $remaining = $invoice->remainingAfterExpenses();
    $rate = $invoice->total_ht > 0 ? (int) round($remaining * 100 / $invoice->total_ht) : null;
    $assujetti = ! $invoice->isFranchise();
@endphp
<div class="card" id="frais">
    <div class="card-head">
        <h2>Frais</h2>
        <span class="badge">Visible par vous seul</span>
    </div>

    <ul class="stat-list">
        <li><span>Montant de la facture{{ $assujetti ? ' HT' : '' }}</span><strong>{{ Money::format((int) $invoice->total_ht) }}</strong></li>
        <li><span>Frais{{ $assujetti ? ' HT' : '' }}</span><strong>− {{ Money::format($totalExpenses) }}</strong></li>
        <li class="remaining"><span>Il vous reste</span><strong class="{{ $remaining < 0 ? 'text-danger' : '' }}">{{ Money::format($remaining) }}@if ($rate !== null) <span class="badge {{ $remaining < 0 ? 'badge-danger' : 'badge-success' }}">{{ $rate }} %</span>@endif</strong></li>
    </ul>

    @if ($expenses->isNotEmpty())
        <ul class="stat-list expense-list">
            @foreach ($expenses as $expense)
                <li>
                    <span>{{ $expense->label }}@if ($expense->receipt_path) · <a class="small" href="{{ str_ends_with(strtolower((string) $expense->receipt_path), '.pdf') ? \App\Http\Controllers\PdfViewerController::link(route('expenses.receipt', $expense), 'Ticket '.$expense->label) : route('expenses.receipt', $expense) }}" @unless (str_ends_with(strtolower((string) $expense->receipt_path), '.pdf')) target="_blank" rel="noopener" @endunless>ticket</a>@endif</span>
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
    @endif

    <details @if ($errors->hasAny(['expense_label', 'expense_amount', 'expense_vat', 'receipt'])) open @endif>
        <summary>Ajouter un frais</summary>
        <form method="POST" action="{{ route('expenses.store', $invoice) }}" enctype="multipart/form-data" class="form-grid cols-2" style="margin-top:.75rem">
            @csrf
            <x-field name="expense_label" label="Description" required placeholder="ex. Tuiles, location échafaudage, déchetterie" />
            <x-field name="expense_amount" :label="$assujetti ? 'Montant payé TTC (€)' : 'Montant (€)'" required inputmode="decimal" placeholder="0,00" />
            @if ($assujetti)
                <x-field name="expense_vat" label="Dont TVA (€)" inputmode="decimal" placeholder="0,00" hint="La TVA récupérable n'est pas déduite." />
            @endif
            <div class="field @error('receipt') has-error @enderror">
                <label for="receipt">Photo du ticket <span class="muted small">(facultatif)</span></label>
                <input id="receipt" type="file" name="receipt" accept="image/*,application/pdf">
                @error('receipt')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="span-2"><button class="btn" type="submit"><x-icon name="plus" /> Ajouter</button></div>
        </form>
    </details>
</div>
