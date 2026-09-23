@props(['variant' => 'icon'])
@php
    // variant « icon » : marque carrée (barre du haut) ; « full » : logo complet (connexion, documents).
    $name = $settings->get('company.trade_name');
    $kind = $variant === 'full' ? 'logo' : 'icon';
    $uploaded = $settings->get("branding.{$kind}_path");
    $default = config("entreprise.default_images.$kind");
    $src = $uploaded
        ? route('branding.image', $kind === 'logo' ? 'logo' : 'icone').'?v='.md5((string) $uploaded)
        : ($default && is_file(public_path($default)) ? asset($default) : null);
    $initials = collect(preg_split('/\s+/', (string) $name))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
@if ($src)
    <img {{ $attributes->merge(['class' => 'brand-img brand-img-'.$variant]) }} src="{{ $src }}" alt="{{ $name }}">
@else
    <span class="brand-mark" aria-hidden="true">{{ $initials }}</span>
@endif
