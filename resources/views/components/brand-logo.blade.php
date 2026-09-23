@php
    $name = $settings->get('company.trade_name');
    $initials = collect(preg_split('/\s+/', (string) $name))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
@if ($settings->get('branding.logo_path'))
    <img src="{{ route('branding.logo') }}?v={{ md5((string) $settings->get('branding.logo_path')) }}" alt="{{ $name }}">
@else
    <span class="brand-mark" aria-hidden="true">{{ $initials }}</span>
@endif
