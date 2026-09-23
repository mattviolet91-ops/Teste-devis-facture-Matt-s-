{{--
    Aperçu commun d'un devis ou d'une facture (la mise en page PDF arrive à la phase 8).
    Attend : $document, $totals, $docTitle, $meta (liste de lignes d'information), $settings.
    Le pied de document spécifique se place dans $slot via @include(..., ['footer' => …]) ou après.
--}}
@php
    use App\Support\Money;
    use App\Support\Percent;
    use App\Support\Quantity;

    $company = $settings->group('company');
    $franchise = $document->isFranchise();
    $columns = $franchise ? 4 : 5;
@endphp

<header class="doc-head">
    <div>
        <x-brand-logo variant="full" />
        <p class="doc-company">
            {{ $company['trade_name'] }} — {{ $company['owner_name'] }} {{ $company['legal_form'] }}<br>
            {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }}<br>
            {{ $company['phone'] }} · {{ $company['email'] }}<br>
            SIRET {{ $company['siret'] }}
        </p>
    </div>
    <div class="doc-meta">
        <p class="doc-title">{{ $docTitle }}</p>
        @foreach ($meta as $line)<p>{{ $line }}</p>@endforeach
    </div>
</header>

<div class="doc-parties">
    @if ($document->client)
        <div>
            <span class="doc-label">Client</span>
            <strong>{{ $document->client->displayName() }}</strong><br>
            @if ($document->client->contactName()){{ $document->client->contactName() }}<br>@endif
            @if ($document->client->fullAddress()){{ $document->client->fullAddress() }}<br>@endif
            {{ collect([$document->client->phone, $document->client->email])->filter()->implode(' · ') }}
        </div>
    @endif
    @if ($document->worksite)
        <div>
            <span class="doc-label">Adresse du chantier</span>
            @if ($document->worksite->label)<strong>{{ $document->worksite->label }}</strong><br>@endif
            {{ $document->worksite->fullAddress() }}
        </div>
    @endif
</div>

@if ($document->title)
    <h2 class="doc-subject">{{ $document->title }}</h2>
@endif

<div class="table-wrap">
    <table class="doc-lines">
        <thead>
            <tr><th>Désignation</th><th class="num">Qté</th><th class="num">Prix unit. HT</th>@unless ($franchise)<th class="num">TVA</th>@endunless<th class="num">Total HT</th></tr>
        </thead>
        <tbody>
            @php $hidePrices = false; @endphp
            @foreach ($document->lines as $index => $line)
                @if ($line->isSection())
                    @php $hidePrices = $line->hide_prices; @endphp
                    <tr class="doc-section"><td colspan="{{ $columns - 1 }}">{{ $line->title }}</td><td class="num">{{ Money::format($totals['sections'][$index] ?? 0) }}</td></tr>
                @elseif ($line->type === 'text')
                    <tr class="doc-text"><td colspan="{{ $columns }}">{!! nl2br(e($line->description)) !!}</td></tr>
                @else
                    <tr @class(['doc-optional' => $line->is_optional])>
                        <td>
                            <strong>{{ $line->is_optional ? '(Option) ' : '' }}{{ $line->title }}</strong>
                            @if ($line->steps())
                                <ul class="doc-steps">@foreach ($line->steps() as $step)<li>{{ $step }}</li>@endforeach</ul>
                            @endif
                        </td>
                        <td class="num">{{ Quantity::format($line->quantity) }} {{ $line->unit }}</td>
                        <td class="num">{{ $hidePrices ? '' : Money::format($line->unit_price) }}</td>
                        @unless ($franchise)<td class="num">{{ Percent::format($line->vat_rate) }}</td>@endunless
                        <td class="num">
                            @if ($line->is_offered) <span class="badge badge-success">Offert</span>
                            @elseif (! $hidePrices) {{ Money::format($line->total_ht) }}
                            @endif
                            @if ($line->discount_percent && ! $hidePrices)<br><span class="small muted">remise {{ Percent::format($line->discount_percent) }}</span>@endif
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
</div>

<dl class="totals doc-totals">
    @if ($totals['discount'])
        <div><dt>Sous-total HT</dt><dd>{{ Money::format($totals['subtotal']) }}</dd></div>
        <div><dt>Remise{{ $document->discount_type === 'percent' ? ' ('.Percent::format($document->discount_value).')' : '' }}</dt><dd>− {{ Money::format($totals['discount']) }}</dd></div>
    @endif
    <div class="strong"><dt>Total HT</dt><dd>{{ Money::format($totals['total_ht']) }}</dd></div>
    @foreach ($totals['vat'] as $rate => $vat)
        <div><dt>TVA {{ Percent::format($rate) }} sur {{ Money::format($vat['base']) }}</dt><dd>{{ Money::format($vat['amount']) }}</dd></div>
    @endforeach
    <div class="grand"><dt>{{ $grandLabel ?? ($franchise ? 'Total à payer' : 'Total TTC') }}</dt><dd>{{ Money::format($totals['total_ttc']) }}</dd></div>
    @if ($totals['optional_total'])
        <div class="muted"><dt>Options proposées (hors total)</dt><dd>{{ Money::format($totals['optional_total']) }}</dd></div>
    @endif
</dl>
