@extends('layouts.app', ['title' => 'Encaisser un montant'])

{{-- Montant libre : déjà reçu (facture créée et réglée tout de suite) ou par carte (lien pour le client). --}}
@section('content')
    <div class="page-head">
        <div>
            <h1>Encaisser un montant</h1>
            <p>Un acompte, un règlement sans facture préparée… La facture est créée automatiquement, numérotée et marquée réglée.</p>
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
            <label for="quote_id">Devis concerné</label>
            <select id="quote_id" name="quote_id" data-collect-quote data-selected="{{ old('quote_id', $quoteId) }}">
                <option value="">Aucun devis</option>
                @foreach ($quotesByClient[old('client_id', $clientId)] ?? [] as $q)
                    <option value="{{ $q['id'] }}" @selected((int) old('quote_id', $quoteId) === $q['id'])>{{ $q['label'] }}</option>
                @endforeach
            </select>
            <span class="hint">Rattaché à un devis accepté, le montant compte dans son « reste à payer » et dans son chantier.</span>
        </div>

        <fieldset class="choice-list">
            <legend>Facture créée *</legend>
            @php $kind = old('kind', old('quote_id', $quoteId) ? 'deposit' : 'standard'); @endphp
            <label class="check"><input type="radio" name="kind" value="deposit" @checked($kind === 'deposit') data-collect-kind> <span><strong>Facture d'acompte</strong><br><span class="small muted">Une avance sur des travaux : elle sera déduite de la facture de solde du devis.</span></span></label>
            <label class="check"><input type="radio" name="kind" value="standard" @checked($kind === 'standard') data-collect-kind> <span><strong>Facture</strong><br><span class="small muted">Une prestation réglée en une fois (petite réparation, dépannage…).</span></span></label>
        </fieldset>

        <x-field name="label" label="Ce que le client règle" required maxlength="150" placeholder="ex. Acompte réfection de toiture" />
        <x-field name="amount" label="Montant TTC (€)" required inputmode="decimal" placeholder="ex. 500" autocomplete="off" />
        @unless ($franchise)
            <div class="field">
                <label for="vat_rate">Taux de TVA *</label>
                <select id="vat_rate" name="vat_rate" required>
                    @foreach ($vatRates as $rate)
                        <option value="{{ $rate->rate }}" @selected((int) old('vat_rate', $defaultRate) === $rate->rate)>{{ $rate->percentLabel() }} — {{ $rate->label }}</option>
                    @endforeach
                </select>
            </div>
        @endunless

        <fieldset class="choice-list">
            <legend>Comment le client paie-t-il ? *</legend>
            @php $mode = old('mode', 'card'); @endphp
            <label class="check"><input type="radio" name="mode" value="card" @checked($mode === 'card') data-collect-mode> <span><strong>Par carte, en ligne</strong><br><span class="small muted">Un lien de paiement à envoyer, ou à ouvrir sur votre téléphone pour qu'il paie sur place. La facture est créée quand le paiement est confirmé.</span></span></label>
            <label class="check"><input type="radio" name="mode" value="received" @checked($mode === 'received') data-collect-mode> <span><strong>Déjà reçu</strong><br><span class="small muted">Espèces, chèque, virement ou carte sur votre terminal : la facture est créée et réglée tout de suite.</span></span></label>
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

    <script type="application/json" id="collect-quotes">@json($quotesByClient)</script>
    <script src="{{ asset('js/collect.js') }}?v={{ filemtime(public_path('js/collect.js')) }}" defer></script>
@endsection
