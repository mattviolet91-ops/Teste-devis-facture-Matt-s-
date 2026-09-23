@extends('layouts.app', ['title' => 'Devis '.$quote->displayNumber()])

@php
    use App\Support\Money;

    $meta = $quote->issue_date
        ? ['Émis le '.$quote->issue_date->format('d/m/Y'), 'Valable jusqu\'au '.$quote->valid_until->format('d/m/Y')]
        : ['Valable '.$quote->validity_days.' jours à compter de l\'envoi'];
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
        @if ($quote->isInvoiceable())
            <button class="btn" type="button" data-open-sheet="invoice-dialog"><x-icon name="receipt" /> Facturer</button>
        @endif
        <button class="btn btn-secondary" type="button" data-open-sheet="duplicate-dialog"><x-icon name="copy" /> Dupliquer</button>
    </div>

    @error('percent')<div class="alert alert-error" role="alert">{{ $message }}</div>@enderror

    @if ($quote->invoices->isNotEmpty())
        @php
            $billed = $quote->invoices->whereIn('status', ['sent', 'paid'])->sum('total_ttc');
        @endphp
        <div class="card">
            <div class="card-head">
                <h2>Facturation</h2>
                <span class="muted small">Facturé {{ Money::format($billed) }} sur {{ Money::format($quote->total_ttc) }}</span>
            </div>
            <ul class="stat-list">
                @foreach ($quote->invoices as $invoice)
                    <li>
                        <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->kindLabel() }} {{ $invoice->displayNumber() }}</a>
                        <span>@include('invoices._status') <strong>{{ Money::format($invoice->total_ttc) }}</strong></span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Aperçu du devis (la mise en page PDF arrive à la phase 8) --}}
    <article class="doc">
        @include('documents._preview', ['document' => $quote, 'docTitle' => 'Devis '.($quote->number ?? '(brouillon)'), 'meta' => $meta])

        <div class="doc-footer">
            @if ($quote->work_start || $quote->work_duration)
                <p><strong>Travaux :</strong> {{ collect([$quote->work_start ? 'début prévu '.$quote->work_start : null, $quote->work_duration ? 'durée estimée '.$quote->work_duration : null])->filter()->implode(' — ') }}</p>
            @endif
            @if ($quote->payment_terms)<p><strong>Conditions de paiement :</strong> {{ $quote->payment_terms }}</p>@endif
            @if ($quote->notes)<p>{!! nl2br(e($quote->notes)) !!}</p>@endif
            @include('documents._mentions', ['document' => $quote])
        </div>
    </article>

    @if ($quote->internal_notes)
        <div class="card">
            <h2>Notes internes</h2>
            <p class="pre-line" style="margin:0">{{ $quote->internal_notes }}</p>
        </div>
    @endif

    @include('documents._history')

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

    @if ($quote->isInvoiceable())
        @php
            $hasPartial = $quote->invoices->whereIn('kind', ['deposit', 'progress'])->whereIn('status', ['sent', 'paid'])->isNotEmpty();
        @endphp
        <dialog class="sheet" id="invoice-dialog" aria-labelledby="invoice-title">
            <form method="POST" action="{{ route('quotes.invoice', $quote) }}">
                @csrf
                <div class="card-head">
                    <h2 id="invoice-title">Facturer le devis</h2>
                    <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
                </div>
                <fieldset class="choice-list">
                    <legend class="visually-hidden">Type de facture</legend>
                    <label class="check"><input type="radio" name="kind" value="deposit" @checked(! $hasPartial) data-kind-percent> <span><strong>Acompte</strong><br><span class="small muted">Un pourcentage du devis, à la signature.</span></span></label>
                    <label class="check"><input type="radio" name="kind" value="progress" data-kind-percent> <span><strong>Situation de travaux</strong><br><span class="small muted">Un pourcentage du devis selon l'avancement.</span></span></label>
                    <label class="check"><input type="radio" name="kind" value="final" @checked($hasPartial)> <span><strong>Solde</strong><br><span class="small muted">Le devis complet, moins les acomptes et situations déjà facturés.</span></span></label>
                    <label class="check"><input type="radio" name="kind" value="standard"> <span><strong>Facture complète</strong><br><span class="small muted">Tout le devis en une seule facture.</span></span></label>
                </fieldset>
                <div class="field" data-percent-field>
                    <label for="invoice_percent">Pourcentage à facturer</label>
                    <input id="invoice_percent" type="text" inputmode="decimal" name="percent" value="{{ \App\Support\Percent::input((int) $settings->get('documents.deposit_percent', 40) * 100) }}">
                </div>
                <div class="form-actions"><button class="btn" type="submit">Préparer la facture</button></div>
            </form>
        </dialog>
    @endif

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
