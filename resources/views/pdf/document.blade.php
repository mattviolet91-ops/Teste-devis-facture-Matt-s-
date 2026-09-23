@php
    use App\Support\Money;
    use App\Support\Percent;
    use App\Support\Quantity;
    use Illuminate\Support\Carbon;

    // Espace fine insécable → espace insécable (présente dans toutes les polices).
    $m = fn (int $cents) => str_replace("\u{202F}", "\u{00A0}", Money::format($cents));
    $franchise = $document->isFranchise();
    $columns = $franchise ? 4 : 5;
    $accent = $colors['color_accent'] ?? '#3CBDE8';
    $primary = $colors['color_primary'] ?? '#494949';
    $ink = $colors['color_text'] ?? '#2C3E50';
    $client = $document->client;
    $isCredit = ! $isQuote && $document->isCredit();

    $title = $isQuote ? 'Devis' : $document->kindLabel();
    $number = $document->number ?? 'Brouillon';

    $meta = [];
    if ($document->issue_date) {
        $meta['Date'] = $document->issue_date->format('d/m/Y');
    }
    if ($isQuote) {
        $meta['Valable jusqu\'au'] = $document->valid_until?->format('d/m/Y') ?? $document->validity_days.' jours après l\'envoi';
    } elseif (! $isCredit) {
        $meta['Échéance'] = $document->due_days === 0 ? 'À réception' : ($document->due_date?->format('d/m/Y') ?? $document->due_days.' jours');
    }
    if (! $isQuote && $document->quote?->number) {
        $meta['Devis n°'] = $document->quote->number;
    }
    if (! $isQuote && $document->cancels) {
        $meta['Annule la facture'] = $document->cancels->number;
    }
    if (! $isQuote && $document->corrects) {
        $meta['Remplace la facture'] = $document->corrects->number;
    }

    $reducedRates = $document->lines->where('type', 'item')->where('is_optional', false)->pluck('vat_rate')->unique()
        ->filter(fn ($rate) => $rate > 0 && $rate < 2000);
    $attestations = $franchise || empty($vat['reduced_rate_mention_enabled']) ? collect()
        : \App\Models\VatRate::query()->whereIn('rate', $reducedRates)->whereNotNull('mention')->pluck('mention')->unique();

    $showBank = $document->show_bank && ! empty($bank['iban']);
@endphp
<html>
<head>
<style>
    body { font-family: figtree; font-size: 9pt; color: {{ $ink }}; line-height: 1.35; }
    h1, h2, h3, .heading { font-family: montserrat; color: {{ $ink }}; }
    .muted { color: #5F6F7D; }
    .small { font-size: 7.5pt; }
    table { border-collapse: collapse; }
    .head td { vertical-align: top; }
    .doc-title { font-family: montserrat; font-weight: bold; font-size: 17pt; color: {{ $ink }}; }
    .doc-number { font-family: montserrat; font-size: 11pt; color: {{ $accent }}; font-weight: bold; }
    .meta td { padding: 1pt 0; font-size: 8.5pt; }
    .meta td.k { color: #5F6F7D; padding-right: 6pt; }
    .rule { border-bottom: 2pt solid {{ $accent }}; height: 1pt; margin: 8pt 0 10pt; }
    .box { border: 0.6pt solid #D5DDE1; padding: 7pt 9pt; vertical-align: top; }
    .label { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #5F6F7D; font-weight: bold; }
    .subject { font-family: montserrat; font-weight: bold; font-size: 11pt; margin: 12pt 0 6pt; }
    table.lines { width: 100%; margin-top: 4pt; }
    table.lines th { background: {{ $primary }}; color: #fff; font-size: 7.5pt; text-transform: uppercase; padding: 5pt 5pt; text-align: left; font-weight: bold; }
    table.lines td { padding: 5pt 5pt; border-bottom: 0.5pt solid #E5EAEC; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    tr.section td { background: #EEF7FB; font-family: montserrat; font-weight: bold; text-transform: uppercase; font-size: 8.5pt; }
    tr.optional td { color: #5F6F7D; font-style: italic; }
    .steps { color: #5F6F7D; font-size: 8pt; margin-top: 2pt; }
    table.totals { width: 75mm; margin-top: 8pt; }
    table.totals td { padding: 3pt 5pt; }
    table.totals tr.strong td { font-weight: bold; }
    table.totals tr.grand td { font-family: montserrat; font-weight: bold; font-size: 11pt; border-top: 1.2pt solid {{ $ink }}; background: #EEF7FB; }
    .notes p { margin: 0 0 5pt; }
    .legal { font-size: 7.5pt; color: #5F6F7D; margin-top: 10pt; border-top: 0.5pt solid #D5DDE1; padding-top: 6pt; }
    .legal p { margin: 0 0 3pt; }
    .sign td { border: 0.6pt solid #D5DDE1; height: 30mm; vertical-align: top; padding: 6pt 8pt; width: 50%; }
    .annex-title { font-family: montserrat; font-weight: bold; font-size: 14pt; margin-bottom: 8pt; border-bottom: 2pt solid {{ $accent }}; padding-bottom: 4pt; }
    .cgv p { margin: 0 0 6pt; text-align: justify; }
    .insurance { background: #F3F9FC; border-left: 3pt solid {{ $accent }}; }
    .insurance-title { font-family: montserrat; font-weight: bold; font-size: 9.5pt; color: {{ $ink }}; text-transform: uppercase; letter-spacing: 0.5pt; }
    .insurance-text { font-size: 7.8pt; color: #3E4F5E; margin-top: 2pt; line-height: 1.4; }
    .cover-title { font-family: montserrat; font-weight: bold; font-size: 24pt; color: {{ $ink }}; }
    .cover-slogan { font-family: montserrat; font-size: 11pt; color: {{ $accent }}; margin-top: 3mm; }
    .cover-kind { font-family: montserrat; font-weight: bold; font-size: 34pt; color: {{ $ink }}; letter-spacing: 1pt; }
    .cover-number { font-family: montserrat; font-weight: bold; font-size: 16pt; color: {{ $accent }}; margin-top: 1mm; }
    .cover-subject { font-size: 13pt; color: {{ $ink }}; margin-top: 4mm; }
    .cover-card { border: 0.6pt solid #D5DDE1; border-top: 2.5pt solid {{ $accent }}; padding: 8pt 10pt; vertical-align: top; font-size: 9.5pt; }
    .cover-strong { font-family: montserrat; font-weight: bold; font-size: 11pt; margin: 2pt 0; }
    .cover-intro { margin-top: 8mm; font-size: 9.5pt; color: #3E4F5E; text-align: justify; }
    .cover-intro p { margin: 0 0 5pt; }
    .cover-badge { font-family: montserrat; font-weight: bold; font-size: 9pt; color: {{ $primary }}; border: 1pt solid {{ $accent }}; padding: 4pt 10pt; }
</style>
</head>
<body>

<htmlpagefooter name="footer">
    <table width="100%" class="small muted" style="border-top: 0.5pt solid #D5DDE1;">
        <tr>
            <td style="padding-top: 3pt;">{{ $company['trade_name'] }} ({{ $company['legal_form'] }}) — {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }} — SIRET {{ $company['siret'] }}@if (! empty($company['vat_number'])) — TVA {{ $company['vat_number'] }}@endif</td>
            <td style="padding-top: 3pt; text-align: right; white-space: nowrap;" width="25%">{{ $number }} — page {PAGENO}/{nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>
@if ($annexes['cover'])
    @include('pdf._cover')
    <pagebreak resetpagenum="1" odd-footer-name="html_footer" odd-footer-value="1" />
@else
    <sethtmlpagefooter name="footer" value="on" />
@endif

{{-- En-tête --}}
<table width="100%" class="head">
    <tr>
        <td width="55%">
            <table><tr><td style="padding: 0 0 8pt 0;">
                @if ($logo)<img src="{{ $logo }}" style="height: 18mm;" />@else<span class="doc-title">{{ $company['trade_name'] }}</span>@endif
            </td></tr></table>
            <div class="small">
                <b>{{ $company['trade_name'] }}</b> ({{ $company['legal_form'] }})<br>
                {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }}<br>
                {{ $company['phone'] }} · {{ $company['email'] }}<br>
                SIRET {{ $company['siret'] }}@if (! empty($company['ape_code'])) — APE {{ $company['ape_code'] }}@endif
                @if (! empty($company['agreements']))<br>{{ $company['agreements'] }}@endif
            </div>
        </td>
        <td width="45%" style="text-align: right;">
            <div class="doc-title">{{ $title }}</div>
            <div class="doc-number">{{ $number }}</div>
            <table class="meta" style="margin-top: 6pt;" align="right">
                @foreach ($meta as $key => $value)
                    <tr><td class="k" style="text-align: right;">{{ $key }}</td><td style="text-align: right;"><b>{{ $value }}</b></td></tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>
<div class="rule"></div>

{{-- Client et chantier --}}
<table width="100%">
    <tr>
        <td class="box" width="50%">
            <div class="label">Client</div>
            @if ($client)
                <b>{{ $client->displayName() }}</b><br>
                @if ($client->contactName()){{ $client->contactName() }}<br>@endif
                @if ($client->fullAddress()){{ $client->fullAddress() }}<br>@endif
                {{ collect([$client->phone, $client->email])->filter()->implode(' · ') }}
                @if (! $client->isIndividual() && $client->siret)<br>SIRET {{ $client->siret }}@endif
            @endif
        </td>
        <td width="3%"></td>
        <td class="box" width="47%">
            <div class="label">Adresse des travaux</div>
            @if ($document->worksite)
                @if ($document->worksite->label)<b>{{ $document->worksite->label }}</b><br>@endif
                {{ $document->worksite->fullAddress() }}
            @elseif ($client?->fullAddress())
                {{ $client->fullAddress() }}
            @endif
        </td>
    </tr>
</table>

@if ($document->title)
    <div class="subject">{{ $document->title }}</div>
@endif

{{-- Lignes --}}
<table class="lines">
    <thead>
        <tr>
            <th>Désignation</th>
            <th class="num" width="15%">Qté</th>
            <th class="num" width="14%">P.U. HT</th>
            @unless ($franchise)<th class="num" width="8%">TVA</th>@endunless
            <th class="num" width="15%">Total HT</th>
        </tr>
    </thead>
    <tbody>
        @php $hidePrices = false; @endphp
        @foreach ($document->lines as $index => $line)
            @if ($line->isSection())
                @php $hidePrices = $line->hide_prices; @endphp
                <tr class="section"><td colspan="{{ $columns - 1 }}">{{ $line->title }}</td><td class="num">{{ $m($totals['sections'][$index] ?? 0) }}</td></tr>
            @elseif ($line->type === 'text')
                <tr><td colspan="{{ $columns }}">{!! nl2br(e($line->description)) !!}</td></tr>
            @else
                <tr class="{{ $line->is_optional ? 'optional' : '' }}">
                    <td>
                        <b>{{ $line->is_optional ? '(Option) ' : '' }}{{ $line->title }}</b>
                        @if ($line->steps())
                            <div class="steps">@foreach ($line->steps() as $step)• {{ $step }}<br>@endforeach</div>
                        @endif
                    </td>
                    <td class="num">{{ Quantity::format($line->quantity) }} {{ $line->unit }}</td>
                    <td class="num">{{ $hidePrices ? '' : $m($line->unit_price) }}</td>
                    @unless ($franchise)<td class="num">{{ Percent::format($line->vat_rate) }}</td>@endunless
                    <td class="num">
                        @if ($line->is_offered) Offert
                        @elseif (! $hidePrices) {{ $m($line->total_ht) }}
                        @endif
                        @if ($line->discount_percent && ! $hidePrices)<br><span class="small muted">remise {{ Percent::format($line->discount_percent) }}</span>@endif
                    </td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>

{{-- Totaux --}}
<table width="100%" style="page-break-inside: avoid;">
    <tr>
        <td width="50%" style="vertical-align: top; padding-top: 8pt;">
            @if ($showBank)
                <div class="box small">
                    <div class="label">Règlement par virement</div>
                    IBAN : <b>{{ trim(chunk_split($bank['iban'], 4, ' ')) }}</b>
                    @if (! empty($bank['bic']))<br>BIC : {{ $bank['bic'] }}@endif
                </div>
            @endif
        </td>
        <td width="50%" style="vertical-align: top;">
            <table class="totals" align="right">
                @if ($totals['discount'])
                    <tr><td>Sous-total HT</td><td class="num">{{ $m($totals['subtotal']) }}</td></tr>
                    <tr><td>Remise{{ $document->discount_type === 'percent' ? ' ('.Percent::format($document->discount_value).')' : '' }}</td><td class="num">− {{ $m($totals['discount']) }}</td></tr>
                @endif
                <tr class="strong"><td>Total HT</td><td class="num">{{ $m($totals['total_ht']) }}</td></tr>
                @foreach ($totals['vat'] as $rate => $row)
                    <tr><td>TVA {{ Percent::format($rate) }} sur {{ $m($row['base']) }}</td><td class="num">{{ $m($row['amount']) }}</td></tr>
                @endforeach
                <tr class="grand">
                    <td>{{ $isCredit ? 'Montant de l\'avoir' : ($franchise ? ($isQuote ? 'Total' : 'Total à payer') : 'Total TTC') }}</td>
                    <td class="num">{{ $m($totals['total_ttc']) }}</td>
                </tr>
                @if (! $isQuote && ! $isCredit && $document->amount_paid > 0)
                    <tr><td>Déjà réglé</td><td class="num">− {{ $m($document->amount_paid) }}</td></tr>
                    <tr class="strong"><td>Reste à payer</td><td class="num">{{ $m($document->balance()) }}</td></tr>
                @endif
                @if ($totals['optional_total'])
                    <tr class="muted"><td>Options (hors total)</td><td class="num">{{ $m($totals['optional_total']) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

{{-- Conditions et informations --}}
<div class="notes" style="margin-top: 10pt;">
    @if ($isQuote && ($document->work_start || $document->work_duration))
        <p><b>Travaux :</b> {{ collect([$document->work_start ? 'début prévu '.$document->work_start : null, $document->work_duration ? 'durée estimée '.$document->work_duration : null])->filter()->implode(' — ') }}</p>
    @endif
    @if (! $isQuote && $document->work_period)
        <p><b>Date des travaux :</b> {{ $document->work_period }}</p>
    @endif
    @if ($document->payment_terms && ! $isCredit)<p><b>Conditions de paiement :</b> {{ $document->payment_terms }}</p>@endif
    @if ($document->notes)<p>{!! nl2br(e($document->notes)) !!}</p>@endif
</div>

@if ($isQuote)
    <div class="notes small">
        <p><b>Gestion des déchets :</b> {{ $pdf['waste_mention'] ?? '' }}@if (! empty($pdf['waste_facility'])) Installation de collecte : {{ $pdf['waste_facility'] }}.@endif
            @if ($document->waste_estimate) Estimation : {{ $document->waste_estimate }}.@endif</p>
    </div>

    <table width="100%" class="sign" style="margin-top: 8pt; page-break-inside: avoid;">
        <tr>
            <td>
                <div class="label">Pour l'entreprise</div>
                {{ $company['trade_name'] }}
            </td>
            <td>
                <div class="label">Bon pour accord du client</div>
                @if ($document->signed_at && $document->signature_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($document->signature_path))
                    <div class="small"><b>Bon pour accord</b> — {{ $document->signed_name }}</div>
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('local')->path($document->signature_path) }}" style="height: 16mm;" />
                    <div class="small muted">Signé électroniquement le {{ $document->signed_at->format('d/m/Y à H:i') }} — IP {{ $document->signed_ip }}</div>
                @else
                    <span class="small muted">Devis reçu avant l'exécution des travaux. Date, signature précédée de la mention manuscrite « Bon pour accord ».</span>
                @endif
            </td>
        </tr>
    </table>
@endif

<div style="margin-top: 10pt;">@include('pdf._insurance')</div>

{{-- Mentions légales --}}
<div class="legal">
    @if ($franchise)<p>{{ $vat['franchise_mention'] ?? 'TVA non applicable, art. 293 B du CGI' }}</p>@endif
    @foreach ($attestations as $mention)<p>{{ $mention }}</p>@endforeach
    @unless ($isQuote)
        <p>Catégorie de l'opération : prestation de services.@unless ($isCredit) Pas d'escompte pour paiement anticipé.@endunless</p>
    @endunless
    @if (! empty($company['mediator_name']))
        <p>En cas de litige, le client consommateur peut recourir gratuitement au médiateur de la consommation : {{ $company['mediator_name'] }}@if (! empty($company['mediator_url'])) — {{ $company['mediator_url'] }}@endif.</p>
    @endif
    @if ($annexes['cgv'])<p>Conditions générales de vente en annexe.</p>@endif
</div>

@if ($document->photos->isNotEmpty())
    <pagebreak />
    <div class="annex-title">Photos du chantier</div>
    <table width="100%">
        @foreach ($document->photos->chunk(2) as $row)
            <tr>
                @foreach ($row as $photo)
                    <td width="50%" style="vertical-align: top; padding: 0 4pt 10pt; page-break-inside: avoid;">
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('local')->path($photo->displayPath()) }}" style="width: 86mm; border: 0.5pt solid #D5DDE1;" />
                        <div class="small" style="margin-top: 3pt;"><b>{{ $photo->categoryLabel() }}</b>{{ $photo->caption ? ' — '.$photo->caption : '' }}</div>
                    </td>
                @endforeach
                @if ($row->count() === 1)<td width="50%"></td>@endif
            </tr>
        @endforeach
    </table>
@endif

@if ($annexes['cgv'])
    <pagebreak />
    <div class="annex-title">Conditions générales de vente</div>
    <div class="cgv small">
        @foreach (preg_split('/\R{1,}/', trim($pdf['cgv'])) as $paragraph)
            @if (trim($paragraph) !== '')<p>{{ $paragraph }}</p>@endif
        @endforeach
    </div>
@endif


</body>
</html>
