{{-- Mentions de TVA : franchise en base ou attestation pour les taux réduits. --}}
@php
    $reducedRates = $document->lines->where('type', 'item')->where('is_optional', false)->pluck('vat_rate')->unique()
        ->filter(fn ($rate) => $rate > 0 && $rate < 2000);
    $attestations = $document->isFranchise() || ! $settings->get('vat.reduced_rate_mention_enabled') ? collect()
        : \App\Models\VatRate::query()->whereIn('rate', $reducedRates)->whereNotNull('mention')->pluck('mention')->unique();
@endphp
@if ($document->isFranchise())<p>{{ $settings->get('vat.franchise_mention') }}</p>@endif
@foreach ($attestations as $mention)<p class="small">{{ $mention }}</p>@endforeach
