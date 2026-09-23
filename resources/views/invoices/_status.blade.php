@php
    $invoiceBadge = match (true) {
        $invoice->isCredit() => 'badge-warning',
        $invoice->isOverdue() => 'badge-danger',
        default => ['draft' => '', 'sent' => 'badge-info', 'paid' => 'badge-success', 'cancelled' => ''][$invoice->status] ?? '',
    };
@endphp
<span class="badge {{ $invoiceBadge }}">{{ $invoice->statusLabel() }}</span>
