@php
    $invoiceBadge = match (true) {
        $invoice->isCredit() => 'badge-warning',
        $invoice->isOverdue() => 'badge-danger',
        default => ['draft' => '', 'sent' => 'badge-info', 'partial' => 'badge-warning', 'paid' => 'badge-success', 'cancelled' => ''][$invoice->status] ?? '',
    };
@endphp
<span class="badge {{ $invoiceBadge }}">@if ($invoice->status === 'sent' && ! $invoice->isCredit() && ! $invoice->isOverdue())<x-icon name="send" class="badge-icon" />@endif{{ $invoice->statusLabel() }}</span>
