{{-- Page de couverture : logo, type de document, client, assurance et coordonnées. --}}
@php
    $validity = $isQuote
        ? ($document->valid_until ? 'Valable jusqu\'au '.$document->valid_until->format('d/m/Y') : 'Valable '.$document->validity_days.' jours')
        : (! $isCredit ? ($document->due_days === 0 ? 'Payable à réception' : ($document->due_date ? 'À régler avant le '.$document->due_date->format('d/m/Y') : '')) : '');
    $siteAddress = $document->worksite?->fullAddress() ?? $client?->fullAddress();
@endphp

{{-- Bandeaux décoratifs --}}
<div style="position: absolute; top: 0; left: 0; width: 210mm; height: 7mm; background: {{ $accent }};"></div>
<div style="position: absolute; top: 7mm; left: 0; width: 210mm; height: 1.2mm; background: {{ $primary }};"></div>
<div style="position: absolute; top: 60mm; left: 0; width: 5mm; height: 120mm; background: {{ $accent }};"></div>

<div style="text-align: center; padding-top: 14mm;">
    @if ($logo)<img src="{{ $logo }}" style="height: 34mm;" />@else<div class="cover-title">{{ $company['trade_name'] }}</div>@endif
    @if (! empty($company['slogan']))<div class="cover-slogan">{{ $company['slogan'] }}</div>@endif
</div>

<div style="margin-top: 26mm; padding-left: 8mm;">
    <div class="cover-kind">{{ mb_strtoupper($title) }}</div>
    <div class="cover-number">{{ $number }}</div>
    @if ($document->title)<div class="cover-subject">{{ $document->title }}</div>@endif
</div>

<table width="100%" style="margin-top: 18mm;">
    <tr>
        <td width="49%" class="cover-card">
            <div class="label">Préparé pour</div>
            @if ($client)<div class="cover-strong">{{ $client->displayName() }}</div>@endif
            @if ($siteAddress)<div>{{ $siteAddress }}</div>@endif
        </td>
        <td width="2%"></td>
        <td width="49%" class="cover-card">
            <div class="label">{{ $document->issue_date ? 'Émis le '.$document->issue_date->format('d/m/Y') : 'Document en préparation' }}</div>
            <div class="cover-strong">{{ $company['trade_name'] }}</div>
            @if ($validity)<div>{{ $validity }}</div>@endif
        </td>
    </tr>
</table>

@if (trim((string) ($pdf['presentation_text'] ?? '')) !== '')
    <div class="cover-intro">
        @foreach (preg_split('/\R{2,}/', trim($pdf['presentation_text'])) as $paragraph)
            <p>{!! nl2br(e(trim($paragraph))) !!}</p>
        @endforeach
    </div>
@endif

<div style="margin-top: 16mm;">
    @include('pdf._insurance')
</div>

@if (! empty($company['agreements']))
    <div style="text-align: center; margin-top: 6mm;"><span class="cover-badge">{{ $company['agreements'] }}</span></div>
@endif

{{-- Bandeau de contact --}}
<div style="position: absolute; bottom: 0; left: 0; width: 210mm; height: 24mm; background: {{ $primary }}; color: #ffffff; text-align: center;">
    <div style="padding-top: 6mm; font-family: montserrat; font-size: 11pt; font-weight: bold; color: #ffffff;">{{ $company['trade_name'] }}</div>
    <div style="font-size: 8.5pt; color: #ffffff; margin-top: 1.5mm;">
        {{ $company['phone'] }} · {{ $company['email'] }}@if (! empty($company['website'])) · {{ preg_replace('#^https?://#', '', $company['website']) }}@endif
    </div>
    <div style="font-size: 7.5pt; color: #D5DDE1; margin-top: 1mm;">{{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }} — SIRET {{ $company['siret'] }}</div>
</div>
