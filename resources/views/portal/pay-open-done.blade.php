@extends('layouts.portal', ['title' => 'Paiement — '.$settings->get('company.trade_name')])

@section('content')
    @if ($paid)
        <div class="card portal-status portal-ok">
            <h1><x-icon name="check" /> Merci !</h1>
            <p>Votre paiement a bien été transmis. Dès sa confirmation par la banque, vous recevez un reçu par email. Votre facture vous sera adressée séparément.</p>
        </div>
    @else
        <div class="card portal-status">
            <h1>Paiement annulé</h1>
            <p>Aucun montant n'a été débité.</p>
            <a class="btn btn-secondary" href="{{ route('portal.pay-open', $code) }}">Recommencer</a>
        </div>
    @endif
@endsection
