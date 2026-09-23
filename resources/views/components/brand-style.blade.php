@php
    $branding = $settings->group('branding');
    $fonts = config('entreprise.fonts');
    $hex = fn ($value, $fallback) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $value) ? $value : $fallback;
@endphp
<style>
    :root {
        --brand-accent: {{ $hex($branding['color_accent'] ?? null, '#3CBDE8') }};
        --brand-primary: {{ $hex($branding['color_primary'] ?? null, '#494949') }};
        --brand-text: {{ $hex($branding['color_text'] ?? null, '#2C3E50') }};
        --brand-background: {{ $hex($branding['color_background'] ?? null, '#ECF0F1') }};
        --font-heading: {!! $fonts[$branding['font_heading'] ?? ''] ?? $fonts['Montserrat'] !!};
        --font-body: {!! $fonts[$branding['font_body'] ?? ''] ?? $fonts['Figtree'] !!};
    }
</style>
