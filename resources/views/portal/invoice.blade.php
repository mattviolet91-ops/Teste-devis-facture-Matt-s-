@extends('layouts.portal', ['title' => $invoice->kindLabel().' '.$invoice->number])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $invoice->kindLabel() }} {{ $invoice->number }}</h1>
            <p>{{ Money::format($invoice->total_ttc) }}@if (! $invoice->isCredit()) — {{ $invoice->legalPaymentFrom() ? 'payable à partir du '.$invoice->legalPaymentFrom()->format('d/m/Y') : ($invoice->due_days === 0 ? 'payable à réception' : 'à régler avant le '.$invoice->due_date?->format('d/m/Y')) }}@endif</p>
        </div>
        <a class="btn" href="{{ route('portal.invoice.pdf', $invoice->public_token) }}" target="_blank" rel="noopener"><x-icon name="file" /> Télécharger le PDF</a>
    </div>

    @if ($invoice->status === 'cancelled')
        <div class="card portal-status"><h2>Facture annulée</h2><p>Cette facture a été annulée et remplacée. Utilisez le lien reçu avec la nouvelle facture.</p></div>
    @elseif ($invoice->status === 'paid')
        <div class="card portal-status portal-ok"><h2><x-icon name="check" /> Facture réglée</h2><p>Merci pour votre règlement.</p></div>
    @endif

    @if ($invoice->acceptsPayments() && $invoice->paymentWaitUntil())
        <div class="card portal-status">
            <h2>Paiement à partir du {{ $invoice->paymentWaitUntil()->format('d/m/Y') }}</h2>
            <p>Le devis a été signé à votre domicile : la loi prévoit un délai de 7 jours avant tout paiement. Vous pourrez régler cette facture à partir de cette date.</p>
        </div>
    @endif

    @if ($cardUrl = $invoice->cardPaymentUrl())
        <div class="card portal-pay">
            <h2>Payer par carte bancaire</h2>
            <p>Reste à régler : <strong>{{ Money::format($invoice->balance()) }}</strong>. Paiement sécurisé chez notre prestataire de paiement.
                @unless ($invoice->payment_link || $cardUrl === route('portal.invoice.pay', array_filter(['token' => $invoice->public_token, 'essai' => request('essai') ? 1 : null]))) Indiquez le montant et la référence <strong>{{ $invoice->number }}</strong>.@endunless</p>
            <a class="btn" href="{{ $cardUrl }}" @if ($cardUrl !== route('portal.invoice.pay', array_filter(['token' => $invoice->public_token, 'essai' => request('essai') ? 1 : null]))) target="_blank" rel="noopener noreferrer" @endif><x-icon name="wallet" /> Payer {{ Money::format($invoice->balance()) }} par carte</a>
            @if ($cardUrl === route('portal.invoice.pay', array_filter(['token' => $invoice->public_token, 'essai' => request('essai') ? 1 : null])) && $invoice->balance() > \App\Services\MyposGateway::MIN_PARTIAL)
                <details style="margin-top:.75rem" @if ($errors->has('montant')) open @endif>
                    <summary>Payer une partie seulement</summary>
                    <form method="GET" action="{{ route('portal.invoice.pay', $invoice->public_token) }}" class="form-grid" style="margin-top:.5rem">
                        @if (request('essai'))<input type="hidden" name="essai" value="1">@endif
                        <div class="field @error('montant') has-error @enderror">
                            <label for="montant">Montant à payer maintenant (€)</label>
                            <input id="montant" name="montant" inputmode="decimal" required autocomplete="off" value="{{ old('montant') }}" placeholder="ex. {{ number_format(intdiv($invoice->balance(), 200) / 100, 2, ',', ' ') }}">
                            <span class="hint">Entre {{ Money::format(\App\Services\MyposGateway::MIN_PARTIAL) }} et {{ Money::format($invoice->balance()) }}. Le reste pourra être réglé plus tard.</span>
                            @error('montant')<span class="error">{{ $message }}</span>@enderror
                        </div>
                        <div><button class="btn btn-secondary" type="submit"><x-icon name="wallet" /> Payer ce montant par carte</button></div>
                    </form>
                </details>
            @endif
            @include('portal.pages._cards')
            <p class="small muted" style="margin-bottom:0">En payant, vous acceptez nos <a href="{{ route('portal.cgv') }}">conditions générales de vente</a> et notre <a href="{{ route('portal.refunds') }}">politique de remboursement et d'annulation</a>.</p>
        </div>
    @endif

    <article class="doc">
        @include('documents._preview', [
            'document' => $invoice,
            'docTitle' => $invoice->fullTitle(),
            'meta' => array_filter(['Émise le '.$invoice->issue_date?->format('d/m/Y'), $invoice->quote?->number ? 'Devis n° '.$invoice->quote->number : null]),
            'grandLabel' => $invoice->isCredit() ? 'Montant de l\'avoir' : null,
        ])
        <div class="doc-footer">
            @if ($invoice->payment_terms && ! $invoice->isCredit())<p><strong>Conditions de paiement :</strong> {{ $invoice->payment_terms }}</p>@endif
            @if (! $invoice->isCredit() && $invoice->legalPaymentFrom())<p><strong>Paiement à partir du {{ $invoice->legalPaymentFrom()->format('d/m/Y') }}</strong> : délai de 7 jours après la signature du devis à votre domicile (art. L221-10 du Code de la consommation).</p>@endif
            @if ($invoice->show_bank && $settings->get('bank.iban'))
                <p><strong>Règlement par virement :</strong> IBAN {{ trim(chunk_split($settings->get('bank.iban'), 4, ' ')) }}@if ($settings->get('bank.bic')) — BIC {{ $settings->get('bank.bic') }}@endif — référence {{ $invoice->number }}</p>
            @endif
            @include('documents._mentions', ['document' => $invoice])
        </div>
    </article>

    @include('portal._photos-documents', ['document' => $invoice])
@endsection
