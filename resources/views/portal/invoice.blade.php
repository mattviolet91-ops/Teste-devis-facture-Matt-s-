@extends('layouts.portal', ['title' => $invoice->kindLabel().' '.$invoice->number])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $invoice->kindLabel() }} {{ $invoice->number }}</h1>
            <p>{{ Money::format($invoice->total_ttc) }}@if (! $invoice->isCredit()) — {{ $invoice->due_days === 0 ? 'payable à réception' : 'à régler avant le '.$invoice->due_date?->format('d/m/Y') }}@endif</p>
        </div>
        <a class="btn" href="{{ route('portal.invoice.pdf', $invoice->public_token) }}" target="_blank" rel="noopener"><x-icon name="file" /> Télécharger le PDF</a>
    </div>

    @if ($invoice->status === 'cancelled')
        <div class="card portal-status"><h2>Facture annulée</h2><p>Cette facture a été annulée et remplacée. Utilisez le lien reçu avec la nouvelle facture.</p></div>
    @elseif ($invoice->status === 'paid')
        <div class="card portal-status portal-ok"><h2><x-icon name="check" /> Facture réglée</h2><p>Merci pour votre règlement.</p></div>
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
            @if ($invoice->show_bank && $settings->get('bank.iban'))
                <p><strong>Règlement par virement :</strong> IBAN {{ trim(chunk_split($settings->get('bank.iban'), 4, ' ')) }}@if ($settings->get('bank.bic')) — BIC {{ $settings->get('bank.bic') }}@endif — référence {{ $invoice->number }}</p>
            @endif
            @include('documents._mentions', ['document' => $invoice])
        </div>
    </article>
@endsection
