{{-- Frais d'un chantier : liste, total, ce qu'il reste, et ajout. Visible par le gérant seul. --}}
@php
    use App\Support\Money;
    $assujetti = ! (($job['quote'] ?? $job['invoice'])->isFranchise());
    $rate = $job['invoiced'] > 0 ? (int) round($job['remaining'] * 100 / $job['invoiced']) : null;
@endphp
<ul class="stat-list">
    @if ($job['billed'])
        <li><span>Facturé{{ $assujetti ? ' HT' : '' }}@unless ($job['fully']) <span class="badge badge-warning">en partie · {{ $job['percent'] }} %</span>@endunless</span><strong>{{ Money::format($job['invoiced']) }}</strong></li>
    @else
        <li><span>Pas encore facturé <span class="muted small">(devis {{ Money::format($job['planned']) }}{{ $assujetti ? ' HT' : '' }})</span></span><strong>{{ Money::format(0) }}</strong></li>
    @endif
    <li><span>Frais du chantier{{ $assujetti ? ' HT' : '' }}</span><strong>− {{ Money::format($job['expenses_total']) }}</strong></li>
    <li class="remaining"><span>Il vous reste</span><strong class="{{ $job['remaining'] < 0 ? 'text-danger' : '' }}">{{ Money::format($job['remaining']) }}@if ($rate !== null) <span class="badge {{ $job['remaining'] < 0 ? 'badge-danger' : 'badge-success' }}">{{ $rate }} %</span>@endif</strong></li>
    @unless ($job['fully'])
        <li><span class="muted">Une fois tout facturé, il vous restera</span><span class="{{ $job['expected'] < 0 ? 'text-danger' : 'muted' }}">{{ Money::format($job['expected']) }}</span></li>
    @endunless
</ul>

@include('expenses._list', ['expenses' => $job['expenses'], 'empty' => 'Aucun frais pour ce chantier.'])

<details id="ajouter" @if (($open ?? false) || $errors->hasAny(['expense_label', 'expense_amount', 'expense_vat', 'expense_date', 'receipt'])) open @endif>
    <summary>Ajouter un frais</summary>
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="form-grid cols-2" style="margin-top:.75rem">
        @csrf
        @isset($retour)<input type="hidden" name="retour" value="{{ $retour }}">@endisset
        @include('expenses._fields', ['assujetti' => $assujetti])
        <div class="span-2"><button class="btn" type="submit"><x-icon name="plus" /> Ajouter</button></div>
    </form>
</details>
