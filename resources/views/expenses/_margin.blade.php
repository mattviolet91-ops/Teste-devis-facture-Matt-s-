{{-- Marge d'un chantier : $m = MarginService::forQuote() --}}
@php use App\Support\Money; @endphp
<ul class="stat-list">
    <li><span>Chiffre d'affaires HT <span class="muted small">({{ $m['basis'] }})</span></span><strong>{{ Money::format($m['revenue']) }}</strong></li>
    <li><span>Achats HT</span><strong>− {{ Money::format($m['costs']) }}</strong></li>
    <li><span>Marge</span><strong class="{{ $m['margin'] < 0 ? 'text-danger' : '' }}">{{ Money::format($m['margin']) }}@if ($m['rate'] !== null) <span class="badge {{ $m['margin'] < 0 ? 'badge-danger' : 'badge-success' }}">{{ $m['rate'] }} %</span>@endif</strong></li>
</ul>
