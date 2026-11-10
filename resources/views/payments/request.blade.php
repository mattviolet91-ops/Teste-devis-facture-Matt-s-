@extends('layouts.app', ['title' => 'Demande de paiement'])

@php
    use App\Support\Money;
    $request = $paymentRequest;
    $client = $request->client;
    $phone = preg_replace('/\D/', '', (string) $client?->phone);
    $whatsappPhone = preg_match('/^0\d{9}$/', $phone) ? '33'.substr($phone, 1) : $phone;
    $url = $request->publicUrl().($myposTest ? '?essai=1' : '');
    $message = "Bonjour,\n\nVoici le lien pour régler par carte « {$request->label} » (".Money::plain($request->amount)."), en toute sécurité :\n{$url}\n\nMerci,\n".$settings->get('company.trade_name');
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ Money::format($request->amount) }}</h1>
            <p>{{ $request->label }} — <a href="{{ route('clients.show', $client) }}">{{ $client?->displayName() }}</a>@if ($request->quote) · devis <a href="{{ route('quotes.show', $request->quote) }}">{{ $request->quote->number }}</a>@endif</p>
        </div>
        <span class="badge {{ $request->status === 'paid' ? 'badge-success' : ($request->isPending() ? 'badge-warning' : '') }}">{{ \App\Models\PaymentRequest::STATUSES[$request->status] }}</span>
    </div>

    @if ($request->status === 'paid')
        <div class="card portal-status portal-ok">
            <h2><x-icon name="check" /> Payé par carte le {{ $request->paid_at->format('d/m/Y à H:i') }}</h2>
            @if ($request->invoice)
                <p>{{ $request->invoice->kindLabel() }} <a href="{{ route('invoices.show', $request->invoice) }}">{{ $request->invoice->number }}</a> créée et réglée.</p>
            @endif
        </div>
    @elseif ($request->status === 'cancelled')
        <div class="card portal-status"><h2>Demande annulée</h2><p>Le lien ne permet plus de payer.</p></div>
    @else
        @if (! $myposReady)
            <div class="alert alert-warning">Le paiement par carte myPOS n'est pas encore activé : le client verra un message lui demandant de vous contacter. <a href="{{ route('settings.payments') }}">Réglages du paiement</a></div>
        @elseif ($myposTest)
            <div class="alert alert-warning">myPOS est en mode test : le lien ci-dessous est un lien d'essai, aucun argent réel ne sera débité et aucune facture ne sera créée.</div>
        @endif

        <div class="card" data-share>
            <div class="card-head">
                <h2>Faire payer le client</h2>
                @if ($request->viewed_at)<span class="badge badge-info">Ouvert le {{ $request->viewed_at->format('d/m/Y à H:i') }}</span>@endif
            </div>
            <p class="small muted">Envoyez le lien, ou ouvrez-le sur votre téléphone pour que le client paie sur place. La {{ mb_strtolower($request->kindLabel()) }} est créée, numérotée et réglée dès que myPOS confirme le paiement.</p>
            <div class="chips chips-wrap">
                <a class="btn btn-sm" href="{{ $url }}" target="_blank" rel="noopener"><x-icon name="wallet" /> Payer sur ce téléphone</a>
                <a class="chip" href="#" data-share-to="whatsapp" data-phone="{{ $whatsappPhone }}"><x-icon name="message" /> WhatsApp</a>
                <a class="chip" href="#" data-share-to="sms" data-phone="{{ $phone }}"><x-icon name="message" /> SMS</a>
                <button class="chip" type="button" data-share-to="copy"><x-icon name="copy" /> Copier le message</button>
            </div>
            <div class="field" style="margin-top:.75rem">
                <label for="share-message">Message</label>
                <textarea id="share-message" rows="7" data-share-message>{{ $message }}</textarea>
            </div>
            <div class="copy-field" style="margin-top:.5rem">
                <input type="text" value="{{ $url }}" readonly aria-label="Lien de paiement" data-copy-source>
                <button class="btn btn-secondary btn-sm" type="button" data-copy>Copier le lien</button>
            </div>
        </div>

        <form method="POST" action="{{ route('payments.requests.email', $request) }}" class="card form-grid" id="email">
            @csrf
            <h2>Envoyer le lien par email</h2>
            @unless ($canEmail)<div class="alert alert-warning">L'envoi des emails n'est pas configuré : <a href="{{ route('settings.emails') }}">Réglages → Emails</a>.</div>@endunless
            <x-field name="to" label="Adresse email du client" type="email" required :value="$client?->email" autocomplete="off" />
            <x-field name="message" label="Message" type="textarea" rows="7" :value="$emailMessage" hint="Le bouton « Payer par carte » est ajouté sous le message." />
            <div><button class="btn btn-secondary" type="submit" @disabled(! $canEmail)><x-icon name="mail" /> Envoyer par email</button></div>
        </form>

        <form method="POST" action="{{ route('payments.requests.cancel', $request) }}" data-confirm="Annuler cette demande ? Le lien ne permettra plus de payer.">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger-outline" type="submit">Annuler la demande</button>
        </form>
    @endif
@endsection
