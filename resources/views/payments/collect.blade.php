@extends('layouts.app', ['title' => 'Encaisser un montant'])

{{-- Montant libre : enregistré sur une facture existante, sinon gardé « à rattacher ». Aucune facture n'est créée. --}}
@section('content')
    <div class="page-head">
        <div>
            <h1>Encaisser un montant</h1>
            <p>Un acompte, un règlement partiel… Choisissez la facture concernée, ou encaissez d'abord et rattachez à une facture plus tard. Aucune facture n'est créée automatiquement.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert"><ul class="error-list">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('payments.requests.store') }}" class="card form-grid" data-collect>
        @csrf
        <div class="field">
            <label for="client_id">Client *</label>
            <select id="client_id" name="client_id" required data-collect-client>
                <option value="">Choisir un client…</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected((int) old('client_id', $clientId) === $client->id)>{{ $client->displayName() }}@if ($client->city) — {{ $client->city }}@endif</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="invoice_id">Facture concernée</label>
            <select id="invoice_id" name="invoice_id" data-collect-invoice data-selected="{{ old('invoice_id', $invoiceId) }}">
                <option value="">Aucune pour l'instant (à rattacher plus tard)</option>
                @foreach ($invoicesByClient[old('client_id', $clientId)] ?? [] as $i)
                    <option value="{{ $i['id'] }}" @selected((int) old('invoice_id', $invoiceId) === $i['id'])>{{ $i['label'] }}</option>
                @endforeach
            </select>
            <span class="hint">Seules les factures envoyées et pas encore soldées de ce client sont proposées. Sans facture, l'encaissement attend dans Paiements → « À rattacher ».</span>
        </div>

        <x-field name="label" label="Ce que le client règle" maxlength="150" placeholder="ex. Acompte réfection de toiture" hint="Obligatoire s'il n'y a pas de facture." />
        <x-field name="amount" label="Montant TTC (€)" required inputmode="decimal" placeholder="ex. 500" autocomplete="off" />

        <fieldset class="choice-list">
            <legend>Comment le client paie-t-il ? *</legend>
            @php $mode = old('mode', 'card'); @endphp
            <label class="check"><input type="radio" name="mode" value="card" @checked($mode === 'card') data-collect-mode> <span><strong>Par carte, en ligne</strong><br><span class="small muted">Un lien de paiement à envoyer, ou à ouvrir sur votre téléphone pour qu'il paie sur place.</span></span></label>
            <label class="check"><input type="radio" name="mode" value="received" @checked($mode === 'received') data-collect-mode> <span><strong>Déjà reçu</strong><br><span class="small muted">Espèces, chèque, virement ou carte sur votre terminal : l'encaissement est enregistré tout de suite.</span></span></label>
        </fieldset>

        @if (! $myposReady || $myposTest)
            <div class="alert alert-warning" data-mode-only="card">{{ $myposReady ? 'myPOS est en mode test : le client ne verra le bouton de paiement qu\'avec un lien d\'essai. ' : 'Le paiement par carte myPOS n\'est pas encore activé : le client verra un message lui demandant de vous contacter. ' }}<a href="{{ route('settings.payments') }}">Réglages du paiement</a></div>
        @endif

        <div class="form-grid cols-2" data-mode-only="received">
            <x-field name="paid_at" label="Date du paiement" type="date" required :value="today()->toDateString()" max="{{ today()->toDateString() }}" />
            <x-select name="method" label="Moyen de paiement" :options="\App\Models\Payment::METHODS" :placeholder="false" value="especes" />
            <x-field name="method_detail" label="Précision (si « Autre »)" maxlength="80" />
            <x-field name="reference" label="Référence (n° de chèque…)" maxlength="80" />
        </div>

        <div class="form-actions"><button class="btn" type="submit"><x-icon name="wallet" /> Encaisser</button>
            <a class="btn btn-secondary" href="{{ route('payments.index') }}">Annuler</a></div>
    </form>

    <script type="application/json" id="collect-invoices">@json($invoicesByClient)</script>
    <script src="{{ asset('js/collect.js') }}?v={{ filemtime(public_path('js/collect.js')) }}" defer></script>
@endsection
