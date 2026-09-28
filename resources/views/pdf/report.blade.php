@php
    $accent = $colors['color_accent'] ?? '#3CBDE8';
    $primary = $colors['color_primary'] ?? '#494949';
    $ink = $colors['color_text'] ?? '#2C3E50';
    $client = $report->client;
    $paragraphs = fn (?string $text) => collect(preg_split('/\R{2,}/', trim((string) $text)))->filter(fn ($p) => trim($p) !== '');
    $photoGroups = \App\Models\Photo::pairBeforeAfter($report->photos);
    $img = fn ($photo) => \Illuminate\Support\Facades\Storage::disk('local')->path($photo->displayPath());
@endphp
<html>
<head>
<style>
    body { font-family: figtree; font-size: 9.5pt; color: {{ $ink }}; line-height: 1.4; }
    .muted { color: #5F6F7D; }
    .small { font-size: 7.5pt; }
    table { border-collapse: collapse; }
    .doc-title { font-family: montserrat; font-weight: bold; font-size: 17pt; color: {{ $ink }}; }
    .doc-date { font-family: montserrat; font-size: 11pt; color: {{ $accent }}; font-weight: bold; }
    .rule { border-bottom: 2pt solid {{ $accent }}; height: 1pt; margin: 8pt 0 10pt; }
    .box { border: 0.6pt solid #D5DDE1; padding: 7pt 9pt; vertical-align: top; }
    .label { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #5F6F7D; font-weight: bold; }
    .subject { font-family: montserrat; font-weight: bold; font-size: 12pt; margin: 12pt 0 4pt; }
    .section { font-family: montserrat; font-weight: bold; font-size: 10pt; text-transform: uppercase; letter-spacing: 0.5pt; color: {{ $primary }};
        border-bottom: 1pt solid {{ $accent }}; padding-bottom: 2pt; margin: 12pt 0 5pt; }
    .text p { margin: 0 0 5pt; }
    .insurance { background: #F3F9FC; border-left: 3pt solid {{ $accent }}; padding: 6pt 9pt; font-size: 7.8pt; color: #3E4F5E; margin-top: 12pt; }
    .photo { vertical-align: top; padding: 0 4pt 10pt; page-break-inside: avoid; }
</style>
</head>
<body>

<htmlpagefooter name="footer">
    <table width="100%" class="small muted" style="border-top: 0.5pt solid #D5DDE1;">
        <tr>
            <td style="padding-top: 3pt;">{{ $company['trade_name'] }} ({{ $company['legal_form'] }}) — {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }} — SIRET {{ $company['siret'] }}</td>
            <td style="padding-top: 3pt; text-align: right; white-space: nowrap;" width="18%">page {PAGENO}/{nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="footer" value="on" />

<table width="100%">
    <tr>
        <td width="55%" style="vertical-align: top;">
            <table><tr><td style="padding: 0 0 8pt 0;">
                @if ($logo)<img src="{{ $logo }}" style="height: 18mm;" />@else<span class="doc-title">{{ $company['trade_name'] }}</span>@endif
            </td></tr></table>
            <div class="small">
                <b>{{ $company['trade_name'] }}</b> ({{ $company['legal_form'] }})<br>
                {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }}<br>
                {{ $company['phone'] }} · {{ $company['email'] }}<br>
                SIRET {{ $company['siret'] }}
                @if (! empty($company['agreements']))<br>{{ $company['agreements'] }}@endif
            </div>
        </td>
        <td width="45%" style="text-align: right; vertical-align: top;">
            <div class="doc-title">Rapport d'intervention</div>
            <div class="doc-date">{{ $report->visit_date->locale('fr')->isoFormat('D MMMM YYYY') }}</div>
        </td>
    </tr>
</table>
<div class="rule"></div>

<table width="100%">
    <tr>
        <td class="box" width="50%">
            <div class="label">Client</div>
            @if ($client)
                <b>{{ $client->displayName() }}</b><br>
                @if ($client->fullAddress()){{ $client->fullAddress() }}<br>@endif
                {{ collect([$client->phone, $client->email])->filter()->implode(' · ') }}
            @endif
        </td>
        <td width="3%"></td>
        <td class="box" width="47%">
            <div class="label">Lieu de l'intervention</div>
            @if ($report->worksite)
                @if ($report->worksite->label)<b>{{ $report->worksite->label }}</b><br>@endif
                {{ $report->worksite->fullAddress() }}
            @else
                {{ $client?->fullAddress() }}
            @endif
        </td>
    </tr>
</table>

<div class="subject">{{ $report->title }}</div>

<div class="section">Constat</div>
<div class="text">@foreach ($paragraphs($report->findings) as $p)<p>{!! nl2br(e(trim($p))) !!}</p>@endforeach</div>

@if (trim((string) $report->work_done) !== '')
    <div class="section">Travaux réalisés</div>
    <div class="text">@foreach ($paragraphs($report->work_done) as $p)<p>{!! nl2br(e(trim($p))) !!}</p>@endforeach</div>
@endif

@if (trim((string) $report->recommendations) !== '')
    <div class="section">Préconisations</div>
    <div class="text">@foreach ($paragraphs($report->recommendations) as $p)<p>{!! nl2br(e(trim($p))) !!}</p>@endforeach</div>
@endif

@if (! empty($insurance['insurer']))
    <div class="insurance">
        <b>Assurance décennale :</b> {{ $insurance['insurer'] }}@if (! empty($insurance['policy_number'])) — contrat n° {{ $insurance['policy_number'] }}@endif
        @if (! empty($insurance['coverage_area'])) — {{ $insurance['coverage_area'] }}@endif.
    </div>
@endif

@if ($report->photos->isNotEmpty())
    <div class="section" style="margin-top: 16pt;">Photos</div>
    @if ($photoGroups['pairs'])
        <table width="100%">
            @foreach ($photoGroups['pairs'] as $pair)
                <tr>
                    @foreach ($pair as $photo)
                        <td width="50%" class="photo">
                            <div class="label" style="margin-bottom: 3pt;">{{ mb_strtoupper($photo->categoryLabel()) }}</div>
                            <img src="{{ $img($photo) }}" style="width: 86mm; border: 0.5pt solid #D5DDE1;" />
                            @if ($photo->caption)<div class="small" style="margin-top: 3pt;">{{ $photo->caption }}</div>@endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif
    @if ($photoGroups['others']->isNotEmpty())
        <table width="100%">
            @foreach ($photoGroups['others']->chunk(2) as $row)
                <tr>
                    @foreach ($row as $photo)
                        <td width="50%" class="photo">
                            <img src="{{ $img($photo) }}" style="width: 86mm; border: 0.5pt solid #D5DDE1;" />
                            <div class="small" style="margin-top: 3pt;"><b>{{ $photo->categoryLabel() }}</b>{{ $photo->caption ? ' — '.$photo->caption : '' }}</div>
                        </td>
                    @endforeach
                    @if ($row->count() === 1)<td width="50%"></td>@endif
                </tr>
            @endforeach
        </table>
    @endif
@endif

<p class="small muted" style="margin-top: 14pt;">Rapport établi par {{ $company['trade_name'] }} à la suite de son intervention du {{ $report->visit_date->format('d/m/Y') }}. Il peut être transmis à votre assurance habitation.</p>

</body>
</html>
