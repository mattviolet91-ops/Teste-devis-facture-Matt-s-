@extends('layouts.app', ['title' => 'Devis '.$quote->displayNumber()])

@php
    use App\Support\Money;
    use App\Support\Percent;
    use App\Support\Quantity;

    $company = $settings->group('company');
    $reducedRates = $quote->lines->where('type', 'item')->where('is_optional', false)->pluck('vat_rate')->unique()
        ->filter(fn ($rate) => $rate > 0 && $rate < 2000);
    $attestations = $quote->isFranchise() || ! $settings->get('vat.reduced_rate_mention_enabled') ? collect()
        : \App\Models\VatRate::query()->whereIn('rate', $reducedRates)->whereNotNull('mention')->pluck('mention')->unique();
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Devis {{ $quote->displayNumber() }}</h1>
            <p>
                @include('quotes._status')
                @if ($quote->client)
                    <a href="{{ route('clients.show', $quote->client) }}">{{ $quote->client->displayName() }}</a>
                @endif
            </p>
        </div>
    </div>

    @error('send')<div class="alert alert-error" role="alert">{{ $message }}</div>@enderror

    @if ($quote->replacedBy)
        <div class="alert alert-warning">Ce devis a été remplacé par le devis <a href="{{ route('quotes.show', $quote->replacedBy) }}">{{ $quote->replacedBy->displayNumber() }}</a>.</div>
    @endif
    @if ($quote->replaces)
        <div class="alert alert-info">
            Nouvelle version du devis <a href="{{ route('quotes.show', $quote->replaces) }}">{{ $quote->replaces->displayNumber() }}</a>{{ $quote->isDraft() ? ' : il sera marqué « remplacé » à l\'envoi de celui-ci.' : '.' }}
        </div>
    @endif
    @if ($quote->status === 'refused' && $quote->refusal_reason)
        <div class="alert alert-error">Motif du refus : {{ $quote->refusal_reason }}</div>
    @endif

    {{-- Actions --}}
    <div class="action-bar">
        @if ($quote->isDraft())
            <a class="btn" href="{{ route('quotes.edit', $quote) }}"><x-icon name="file" /> Modifier</a>
            <form method="POST" action="{{ route('quotes.send', $quote) }}" data-confirm="Marquer ce devis comme envoyé ? Il recevra son numéro définitif et ne sera plus modifiable.">
                @csrf
                <button class="btn btn-secondary" type="submit"><x-icon name="send" /> Marquer comme envoyé</button>
            </form>
        @endif
        @if ($quote->awaitsAnswer())
            <form method="POST" action="{{ route('quotes.accept', $quote) }}" data-confirm="Le client a accepté ce devis ?">
                @csrf
                <button class="btn" type="submit"><x-icon name="check" /> Accepté</button>
            </form>
            <button class="btn btn-secondary" type="button" data-open-sheet="refuse-dialog"><x-icon name="x" /> Refusé</button>
        @endif
        @if (! $quote->isDraft() && $quote->status !== 'replaced')
            <form method="POST" action="{{ route('quotes.revise', $quote) }}">
                @csrf
                <button class="btn btn-secondary" type="submit"><x-icon name="file" /> Nouvelle version</button>
            </form>
        @endif
        <button class="btn btn-secondary" type="button" data-open-sheet="duplicate-dialog"><x-icon name="copy" /> Dupliquer</button>
    </div>

    {{-- Aperçu du devis (la mise en page PDF arrive à la phase 8) --}}
    <article class="doc">
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
                <p class="doc-title">Devis {{ $quote->number ?? '(brouillon)' }}</p>
                @if ($quote->issue_date)
                    <p>Émis le {{ $quote->issue_date->format('d/m/Y') }}<br>Valable jusqu'au {{ $quote->valid_until->format('d/m/Y') }}</p>
                @else
                    <p>Valable {{ $quote->validity_days }} jours à compter de l'envoi</p>
                @endif
            </div>
        </header>

        <div class="doc-parties">
            @if ($quote->client)
                <div>
                    <span class="doc-label">Client</span>
                    <strong>{{ $quote->client->displayName() }}</strong><br>
                    @if ($quote->client->contactName()){{ $quote->client->contactName() }}<br>@endif
                    @if ($quote->client->fullAddress()){{ $quote->client->fullAddress() }}<br>@endif
                    {{ collect([$quote->client->phone, $quote->client->email])->filter()->implode(' · ') }}
                </div>
            @endif
            @if ($quote->worksite)
                <div>
                    <span class="doc-label">Adresse du chantier</span>
                    @if ($quote->worksite->label)<strong>{{ $quote->worksite->label }}</strong><br>@endif
                    {{ $quote->worksite->fullAddress() }}
                </div>
            @endif
        </div>

        @if ($quote->title)
            <h2 class="doc-subject">{{ $quote->title }}</h2>
        @endif

        <div class="table-wrap">
            <table class="doc-lines">
                <thead>
                    <tr><th>Désignation</th><th class="num">Qté</th><th class="num">Prix unit. HT</th>@unless ($quote->isFranchise())<th class="num">TVA</th>@endunless<th class="num">Total HT</th></tr>
                </thead>
                <tbody>
                    @php $hidePrices = false; @endphp
                    @foreach ($quote->lines as $index => $line)
                        @if ($line->isSection())
                            @php $hidePrices = $line->hide_prices; @endphp
                            <tr class="doc-section"><td colspan="{{ $quote->isFranchise() ? 3 : 4 }}">{{ $line->title }}</td><td class="num">{{ Money::format($totals['sections'][$index] ?? 0) }}</td></tr>
                        @elseif ($line->type === 'text')
                            <tr class="doc-text"><td colspan="{{ $quote->isFranchise() ? 4 : 5 }}">{!! nl2br(e($line->description)) !!}</td></tr>
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
                                @unless ($quote->isFranchise())<td class="num">{{ Percent::format($line->vat_rate) }}</td>@endunless
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
                <div><dt>Remise{{ $quote->discount_type === 'percent' ? ' ('.Percent::format($quote->discount_value).')' : '' }}</dt><dd>− {{ Money::format($totals['discount']) }}</dd></div>
            @endif
            <div class="strong"><dt>Total HT</dt><dd>{{ Money::format($totals['total_ht']) }}</dd></div>
            @foreach ($totals['vat'] as $rate => $vat)
                <div><dt>TVA {{ Percent::format($rate) }} sur {{ Money::format($vat['base']) }}</dt><dd>{{ Money::format($vat['amount']) }}</dd></div>
            @endforeach
            <div class="grand"><dt>{{ $quote->isFranchise() ? 'Total à payer' : 'Total TTC' }}</dt><dd>{{ Money::format($totals['total_ttc']) }}</dd></div>
            @if ($totals['optional_total'])
                <div class="muted"><dt>Options proposées (hors total)</dt><dd>{{ Money::format($totals['optional_total']) }}</dd></div>
            @endif
        </dl>

        <div class="doc-footer">
            @if ($quote->work_start || $quote->work_duration)
                <p><strong>Travaux :</strong> {{ collect([$quote->work_start ? 'début prévu '.$quote->work_start : null, $quote->work_duration ? 'durée estimée '.$quote->work_duration : null])->filter()->implode(' — ') }}</p>
            @endif
            @if ($quote->payment_terms)<p><strong>Conditions de paiement :</strong> {{ $quote->payment_terms }}</p>@endif
            @if ($quote->notes)<p>{!! nl2br(e($quote->notes)) !!}</p>@endif
            @if ($quote->isFranchise())<p>{{ $settings->get('vat.franchise_mention') }}</p>@endif
            @foreach ($attestations as $mention)<p class="small">{{ $mention }}</p>@endforeach
        </div>
    </article>

    @if ($quote->internal_notes)
        <div class="card">
            <h2>Notes internes</h2>
            <p class="pre-line" style="margin:0">{{ $quote->internal_notes }}</p>
        </div>
    @endif

    <div class="card">
        <div class="card-head"><h2>Historique</h2></div>
        <ol class="timeline">
            @foreach ($history as $event)
                <li><span class="muted small">{{ $event->created_at->format('d/m/Y H:i') }}</span><span>{{ $event->description }}</span></li>
            @endforeach
        </ol>
    </div>

    @if ($quote->isDraft())
        <form method="POST" action="{{ route('quotes.destroy', $quote) }}" data-confirm="Mettre ce brouillon à la corbeille ?">
            @csrf
            @method('DELETE')
            <div class="form-actions"><button class="btn btn-danger-outline" type="submit">Mettre le brouillon à la corbeille</button></div>
        </form>
    @endif

    <dialog class="sheet" id="refuse-dialog" aria-labelledby="refuse-title">
        <form method="POST" action="{{ route('quotes.refuse', $quote) }}">
            @csrf
            <div class="card-head">
                <h2 id="refuse-title">Devis refusé</h2>
                <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
            </div>
            <div class="field">
                <label for="refusal_reason">Motif (facultatif)</label>
                <textarea id="refusal_reason" name="refusal_reason" rows="3" placeholder="ex. trop cher, a choisi un autre artisan, reporte les travaux…"></textarea>
            </div>
            <div class="form-actions"><button class="btn btn-danger" type="submit">Marquer comme refusé</button></div>
        </form>
    </dialog>

    <dialog class="sheet" id="duplicate-dialog" aria-labelledby="duplicate-title">
        <form method="POST" action="{{ route('quotes.duplicate', $quote) }}">
            @csrf
            <div class="card-head">
                <h2 id="duplicate-title">Dupliquer le devis</h2>
                <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
            </div>
            <div class="field">
                <label for="duplicate_client">Pour le client</label>
                <select id="duplicate_client" name="client_id">
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected($client->id === $quote->client_id)>{{ $client->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-actions"><button class="btn" type="submit">Créer la copie</button></div>
        </form>
    </dialog>
@endsection
