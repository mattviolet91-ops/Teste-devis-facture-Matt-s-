@extends('layouts.portal', ['title' => 'Paiement par carte — '.$settings->get('company.trade_name')])

@php use App\Support\Money; @endphp

{{-- Lien permanent « montant libre » : le client indique lui-même le motif et le montant. --}}
@section('content')
    <div class="card portal-pay">
        <h1>Régler par carte bancaire</h1>
        <p>Paiement sécurisé sur la page de notre prestataire <strong>myPOS</strong> : vos données de carte ne nous sont jamais transmises. Une facture vous est envoyée par email après le paiement.</p>
        @include('portal.pages._cards')
    </div>

    @if ($test)<div class="alert alert-warning">Mode TEST : aucun argent réel ne sera débité.</div>@endif

    @unless ($available)
        <div class="card"><p style="margin:0">Le paiement par carte en ligne n'est pas disponible pour le moment. Contactez-nous au
            <a href="tel:{{ preg_replace('/\s+/', '', (string) $settings->get('company.phone')) }}">{{ $settings->get('company.phone') }}</a>.</p></div>
    @else
        @if ($errors->any())
            <div class="alert alert-error" role="alert"><ul class="error-list">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('portal.pay-open.start', array_filter(['code' => $code, 'essai' => request('essai') ? 1 : null])) }}" class="card form-grid">
            @csrf
            <h2>Votre paiement</h2>
            <div class="form-grid cols-2">
                <x-field name="first_name" label="Prénom" required maxlength="80" autocomplete="given-name" />
                <x-field name="last_name" label="Nom" required maxlength="80" autocomplete="family-name" />
            </div>
            <x-field name="email" label="Adresse email" type="email" required maxlength="160" autocomplete="email" hint="Pour recevoir votre facture." />
            <x-field name="phone" label="Téléphone (facultatif)" type="tel" maxlength="30" autocomplete="tel" />
            <x-field name="label" label="Motif du paiement" required maxlength="150" placeholder="ex. Acompte réfection de toiture, facture FAC-2026-0012…" />
            <x-field name="amount" label="Montant à payer (€)" required inputmode="decimal" autocomplete="off" placeholder="ex. 250"
                :hint="'Entre '.Money::format($min).' et '.Money::format($max).'. Vérifiez bien le montant avant de continuer.'" />
            {{-- Champ piège pour les robots (invisible). --}}
            <div class="visually-hidden" aria-hidden="true"><label>Ne pas remplir <input type="text" name="site_web" tabindex="-1" autocomplete="off"></label></div>
            <label class="check"><input type="checkbox" name="agree" value="1" required @checked(old('agree'))>
                <span>J'accepte les <a href="{{ route('portal.cgv') }}" target="_blank" rel="noopener">conditions générales de vente</a> et la <a href="{{ route('portal.refunds') }}" target="_blank" rel="noopener">politique de remboursement et d'annulation</a>.</span></label>
            <div><button class="btn" type="submit"><x-icon name="wallet" /> Continuer vers le paiement sécurisé</button></div>
            <p class="small muted" style="margin:0">Vous avez signé un devis à votre domicile il y a moins de 7 jours ? La loi prévoit un délai de 7 jours avant tout paiement : attendez notre facture.</p>
        </form>
    @endunless
@endsection
