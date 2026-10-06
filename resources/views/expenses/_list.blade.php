{{-- Liste de frais (date, type, ticket, suppression). --}}
@php use App\Support\Money; @endphp
@if ($expenses->isNotEmpty())
    <ul class="stat-list expense-list">
        @foreach ($expenses as $expense)
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
    <p class="muted small">{{ $empty }}</p>
@endif
