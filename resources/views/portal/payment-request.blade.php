@extends('layouts.portal', ['title' => 'Paiement — '.$settings->get('company.trade_name')])

@php use App\Support\Money; $request = $paymentRequest; @endphp

@section('content')
    <div class="card portal-pay">
        <h1>{{ Money::format($request->amount) }}</h1>
        <p><strong>{{ $request->label }}</strong><br><span class="muted">{{ $settings->get('company.trade_name') }} — pour {{ $request->client?->displayName() }}</span></p>

        @if ($request->status === 'paid')
            <div class="portal-status portal-ok"><h2><x-icon name="check" /> Paiement reçu</h2>
                <p>Merci pour votre règlement du {{ $request->paid_at->format('d/m/Y') }}.@if ($request->invoice?->public_token) <a href="{{ route('portal.invoice', $request->invoice->public_token) }}">Voir la facture</a>@endif</p></div>
        @elseif ($request->status === 'cancelled')
            <p>Cette demande de paiement n'est plus valable. Contactez-nous si besoin.</p>
        @elseif ($legalProblem)
            <p>{{ $legalProblem }}</p>
        @elseif ($canPay)
            <p class="muted small">Paiement sécurisé sur la page de notre prestataire myPOS : vos données de carte ne nous sont jamais transmises. Une facture vous est remise après le paiement.</p>
            <a class="btn" href="{{ route('portal.payment-request.pay', array_filter(['token' => $request->token, 'essai' => request('essai') ? 1 : null])) }}"><x-icon name="wallet" /> Payer {{ Money::format($request->amount) }} par carte</a>
            @include('portal.pages._cards')
            <p class="small muted" style="margin-bottom:0">En payant, vous acceptez nos <a href="{{ route('portal.cgv') }}">conditions générales de vente</a> et notre <a href="{{ route('portal.refunds') }}">politique de remboursement et d'annulation</a>.</p>
        @else
            <p>Le paiement par carte en ligne n'est pas disponible pour le moment. Contactez-nous au <a href="tel:{{ preg_replace('/\s+/', '', (string) $settings->get('company.phone')) }}">{{ $settings->get('company.phone') }}</a>.</p>
        @endif
    </div>
@endsection
