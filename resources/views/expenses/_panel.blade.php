{{-- Frais d'un chantier : liste, total, ce qu'il reste, et ajout. Visible par le gérant seul. --}}
@php
    use App\Support\Money;
    $assujetti = ! $job['franchise'];
    $ttc = $assujetti ? ' TTC' : '';
    $rate = $job['invoiced_ttc'] > 0 ? (int) round($job['remaining_ttc'] * 100 / $job['invoiced_ttc']) : null;
@endphp
<ul class="stat-list">
    @if ($job['billed'])
        <li><span>Facturé{{ $ttc }}@unless ($job['fully']) <span class="badge badge-warning">en partie · {{ $job['percent'] }} %</span>@endunless</span><strong>{{ Money::format($job['invoiced_ttc']) }}</strong></li>
    @else
        <li><span>Pas encore facturé <span class="muted small">(prévu {{ Money::format($job['planned_ttc']) }}{{ $ttc }})</span></span><strong>{{ Money::format(0) }}</strong></li>
    @endif
    <li><span>Frais du chantier{{ $ttc }}</span><strong>{{ $job['expenses_ttc'] ? '− ' : '' }}{{ Money::format($job['expenses_ttc']) }}</strong></li>
    <li class="remaining"><span>Il vous reste{{ $ttc }}</span><strong class="{{ $job['remaining_ttc'] < 0 ? 'text-danger' : '' }}">{{ Money::format($job['remaining_ttc']) }}@if ($rate !== null) <span class="badge {{ $job['remaining_ttc'] < 0 ? 'badge-danger' : 'badge-success' }}">{{ $rate }} %</span>@endif</strong></li>
    @unless ($job['fully'])
        <li><span class="muted">Une fois tout facturé, il vous restera</span><span class="{{ $job['expected_ttc'] < 0 ? 'text-danger' : 'muted' }}">{{ Money::format($job['expected_ttc']) }}</span></li>
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
