@php
    $invoiceBadge = match (true) {
        $invoice->isCredit() => 'badge-warning',
        $invoice->isOverdue() => 'badge-danger',
        default => ['draft' => '', 'sent' => 'badge-info', 'partial' => 'badge-warning', 'paid' => 'badge-success', 'cancelled' => ''][$invoice->status] ?? '',
    };
@endphp
@php
    $sentIcon = $invoice->status === 'sent' && ! $invoice->isCredit() && ! $invoice->isOverdue();
    $seen = $sentIcon && $invoice->viewed_at;
@endphp
<span class="badge {{ $invoiceBadge }}" @if ($seen) title="Vue par le client le {{ $invoice->viewed_at->format('d/m/Y à H:i') }}" @endif>@if ($sentIcon)<x-icon :name="$seen ? 'eye' : 'send'" class="badge-icon" />@endif{{ $invoice->statusLabel() }}@if ($seen)<span class="visually-hidden"> et vue par le client</span>@endif</span>
