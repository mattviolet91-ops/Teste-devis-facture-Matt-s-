@extends('layouts.portal', ['title' => 'Paiement en ligne — '.$settings->get('company.trade_name')])

@section('content')
    <div class="card">
        <h1>Régler une facture par carte</h1>
        <p>Le paiement se fait sur la page sécurisée de notre prestataire <strong>myPOS</strong> : vos données de carte ne nous sont jamais transmises. Vous payez le montant exact de la facture, puis vous recevez la confirmation.</p>
        @include('portal.pages._cards')
        <ol class="guide-steps">
            <li>Ouvrez le lien reçu avec votre facture (email ou SMS), ou retrouvez-la ci-dessous.</li>
            <li>Touchez « Payer par carte » : la page de paiement myPOS s'ouvre.</li>
            <li>Saisissez votre carte et validez (avec la confirmation de votre banque si elle vous la demande).</li>
            <li>La facture est marquée réglée dès que le paiement est confirmé.</li>
        </ol>
    </div>

    <form method="POST" action="{{ route('portal.payment.find') }}" class="card form-grid">
        @csrf
        <h2>Retrouver ma facture</h2>
        <x-field name="number" label="Numéro de facture" required placeholder="ex. FAC-2026-0012" autocomplete="off" />
        <x-field name="check" label="Votre email ou votre code postal" required hint="Ceux indiqués sur la facture." autocomplete="off" />
        <div><button class="btn" type="submit"><x-icon name="search" /> Afficher ma facture</button></div>
    </form>

    <p class="small muted">En réglant une facture, vous acceptez nos <a href="{{ route('portal.cgv') }}">conditions générales de vente</a> et notre <a href="{{ route('portal.refunds') }}">politique de remboursement et d'annulation</a>.</p>
@endsection
