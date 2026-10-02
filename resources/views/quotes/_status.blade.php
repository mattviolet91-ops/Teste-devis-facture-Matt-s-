@php
    $classes = ['draft' => '', 'sent' => 'badge-info', 'accepted' => 'badge-success', 'refused' => 'badge-danger', 'expired' => 'badge-warning', 'replaced' => ''];
@endphp
@php $seen = $quote->status === 'sent' && $quote->viewed_at; @endphp
<span class="badge {{ $classes[$quote->status] ?? '' }}" @if ($seen) title="Vu par le client le {{ $quote->viewed_at->format('d/m/Y à H:i') }}" @endif>@if ($quote->status === 'sent')<x-icon :name="$seen ? 'eye' : 'send'" class="badge-icon" />@endif{{ $quote->statusLabel() }}@if ($seen)<span class="visually-hidden"> et vu par le client</span>@endif</span>
