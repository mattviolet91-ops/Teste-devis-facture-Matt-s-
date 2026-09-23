@php
    $classes = ['draft' => '', 'sent' => 'badge-info', 'accepted' => 'badge-success', 'refused' => 'badge-danger', 'expired' => 'badge-warning', 'replaced' => ''];
@endphp
<span class="badge {{ $classes[$quote->status] ?? '' }}">{{ $quote->statusLabel() }}</span>
