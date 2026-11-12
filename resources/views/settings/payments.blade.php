@extends('settings.layout', ['title' => 'Paiement en ligne'])

@php use App\Support\Money; @endphp

@section('settings')
<form method="POST" action="{{ route('settings.payments') }}" class="card">
    @csrf
    @method('PUT')
    <h2>Paiement par carte avec myPOS</h2>
    <p class="muted small">Le client clique sur « Payer par carte » depuis sa facture en ligne, paie le montant exact sur la page sécurisée de myPOS,
        et la facture est marquée payée automatiquement. Vous recevez une notification. L'argent arrive sur votre compte myPOS.</p>

    <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $enabled))>
        <span><strong>Proposer le paiement par carte</strong> sur les factures en ligne</span></label>
    <label class="check"><input type="checkbox" name="test" value="1" @checked(old('test', $test))>
        <span><strong>Mode test</strong> — aucun argent réel, rien n'est enregistré sur les factures, et vos clients ne voient pas le bouton.
            Décochez quand myPOS a validé votre boutique : votre pack sera alors utilisé.</span></label>

    <label class="check"><input type="checkbox" name="email_receipt" value="1" @checked(old('email_receipt', $emailReceipt))>
        <span><strong>Envoyer un email au client</strong> après son paiement par carte (s'il a une adresse email) : la facture s'il a payé une facture, sinon un simple reçu.</span></label>

    <div class="field" style="margin-top:1rem">
        <label for="package">Pack de configuration myPOS</label>
        @if ($hasPackage)
            <p class="small">✅ Enregistré (boutique {{ $sid }}). Collez-en un nouveau seulement pour le remplacer.</p>
        @else
            <p class="small muted">Pas encore enregistré. Le mode test fonctionne sans pack (accès de test public de myPOS).</p>
        @endif
        <textarea id="package" name="package" rows="4" autocomplete="off" spellcheck="false" placeholder="eyJzaWQiOi…"></textarea>
        @error('package')<span class="error">{{ $message }}</span>@enderror
        <span class="hint">Dans votre compte myPOS (sur ordinateur) : Boutiques en ligne → votre boutique → Intégration → « Configuration pack ». Il est enregistré chiffré et n'est jamais réaffiché.</span>
    </div>
    <fieldset id="lien-libre" style="border:0;padding:0;margin:1.25rem 0 0">
        <legend><strong>Lien de paiement à montant libre</strong></legend>
        <p class="muted small" style="margin:.25rem 0 .5rem">Un lien permanent où le client indique lui-même son nom, le motif et le montant. Il est affiché dans Paiements.</p>
        <label class="check"><input type="checkbox" name="free_link" value="1" @checked(old('free_link', $freeLinkEnabled))> <span>Activer ce lien</span></label>
        <div class="form-grid cols-2" style="margin-top:.5rem">
            <x-field name="free_min" label="Montant minimum (€)" type="number" min="1" max="10000" :value="intdiv($freeBounds[0], 100)" />
            <x-field name="free_max" label="Montant maximum (€)" type="number" min="1" max="50000" :value="intdiv($freeBounds[1], 100)" />
        </div>
        @if ($freeLinkUrl)
            <p class="small" style="margin:.5rem 0 0;overflow-wrap:anywhere">Lien actuel : {{ $freeLinkUrl }}</p>
            <label class="check small"><input type="checkbox" name="free_renew" value="1"> <span>Créer un nouveau lien (l'ancien ne fonctionnera plus)</span></label>
        @endif
    </fieldset>

    @if ($hasPackage)
        <label class="check small"><input type="checkbox" name="forget" value="1"> <span>Supprimer le pack enregistré</span></label>
    @endif
    <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
</form>

<div class="card" id="test-connexion">
    <h2>Tester la connexion avec myPOS</h2>
    <p class="small muted">Vérifie que myPOS accepte le pack enregistré, sans aucun paiement. À faire après avoir collé un nouveau pack.</p>
    @if ($check = session('mypos_check'))
        <div class="alert {{ $check['ok'] ? 'alert-success' : 'alert-error' }}" role="status">{{ $check['message'] }}</div>
    @endif
    <form method="POST" action="{{ route('settings.payments.check') }}">
        @csrf
        <button class="btn btn-secondary" type="submit" @disabled(! $hasPackage)><x-icon name="check" /> Tester la connexion avec myPOS</button>
        @unless ($hasPackage)<span class="small muted">Enregistrez d'abord votre pack de configuration.</span>@endunless
    </form>
</div>

@if ($enabled && $test)
    <div class="card">
        <h2>Faire un essai</h2>
        @if ($trialUrl)
            <p class="small">Ouvrez ce lien (c'est la dernière facture en attente, vue comme par le client), appuyez sur « Payer par carte » et utilisez la carte de test myPOS.
                Aucun argent n'est débité et la facture n'est pas modifiée. Vous recevez une notification « [TEST] » si tout fonctionne.</p>
            <div class="copy-field">
                <input type="text" value="{{ $trialUrl }}" readonly aria-label="Lien d'essai" data-copy-source>
                <a class="btn btn-sm" href="{{ $trialUrl }}" target="_blank" rel="noopener">Ouvrir</a>
            </div>
        @else
            <p class="small muted">Il faut une facture envoyée et pas encore réglée pour faire l'essai.</p>
        @endif
    </div>
@endif

@if ($attempts->isNotEmpty())
    <div class="card">
        <h2>Derniers paiements par carte</h2>
        <ul class="stat-list">
            @foreach ($attempts as $attempt)
                <li>
                    <span>{{ $attempt->invoice?->number ?? $attempt->paymentRequest?->label ?? 'Paiement' }} · {{ ($attempt->invoice?->client ?? $attempt->paymentRequest?->client)?->displayName() }} <span class="muted small">{{ $attempt->created_at->format('d/m/Y H:i') }}{{ $attempt->test ? ' · test' : '' }}</span>
                        @if ($attempt->error)<br><span class="small" style="color:var(--danger, #c0392b)">{{ $attempt->error }}</span>@endif</span>
                    <span><strong>{{ Money::format($attempt->amount) }}</strong> <span class="badge">{{ ['pending' => 'commencé', 'paid' => 'payé', 'cancelled' => 'annulé', 'error' => 'erreur'][$attempt->status] ?? $attempt->status }}</span></span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
@endsection
