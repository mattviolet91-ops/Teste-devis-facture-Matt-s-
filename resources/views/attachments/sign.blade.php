@extends('layouts.portal', ['title' => 'Document à signer'])

{{--
    Signature d'un document : sur le téléphone de l'entreprise tendu au client ($onSite),
    ou par le client depuis son lien. Pas de menu de gestion.
--}}
@section('content')
    @if ($back)
        <p><a class="small" href="{{ $back }}">← Retour (réservé à l'entreprise)</a></p>
    @endif

    <div class="page-head">
        <div>
            <h1>{{ $attachment->isSigned() ? 'Document signé' : 'Document à signer' }}</h1>
            <p>{{ $attachment->name }}@if ($attachment->quote?->number) — devis n° {{ $attachment->quote->number }}@elseif ($attachment->invoice?->number) — {{ mb_strtolower($attachment->invoice->kindLabel()) }} n° {{ $attachment->invoice->number }}@endif</p>
        </div>
    </div>

    @if ($attachment->isSigned())
        <div class="card portal-status portal-ok">
            <h2><x-icon name="check" /> Document signé</h2>
            <p>Signé par <strong>{{ $attachment->signed_name }}</strong> le {{ $attachment->signed_at->format('d/m/Y à H:i') }}. Merci !</p>
            <a class="btn btn-secondary" href="{{ $fileUrl }}?telecharger=1"><x-icon name="file" /> Télécharger le document signé</a>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error" role="alert"><ul class="error-list">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <div class="card">
        <h2>{{ $attachment->isSigned() ? 'Le document' : '1. Lisez le document' }}</h2>
        @if ($attachment->isImage() && ! $attachment->isSigned())
            <a href="{{ $fileUrl }}" target="_blank" rel="noopener"><img class="doc-preview-image" src="{{ $fileUrl }}" alt="{{ $attachment->name }}"></a>
            <p class="small muted" style="margin-bottom:0">Touchez l'image pour l'agrandir.</p>
        @else
            <p class="muted small" style="margin-top:0">Le document s'ouvre dans un nouvel onglet. Revenez ensuite sur cette page pour signer.</p>
            <a class="btn btn-secondary" href="{{ $fileUrl }}" target="_blank" rel="noopener"><x-icon name="file" /> Lire « {{ $attachment->currentName() }} »</a>
        @endif
    </div>

    @unless ($attachment->isSigned())
        <form method="POST" action="{{ $action }}" class="card" data-signature-form>
            @csrf
            <h2>2. Signez</h2>
            <p class="muted small">Votre signature électronique a la même valeur qu'une signature sur papier. La date et l'heure{{ $onSite ? '' : ' ainsi que l\'adresse IP de connexion' }} sont enregistrées.
                Vous {{ $onSite ? 'pourrez recevoir' : 'pourrez télécharger' }} une copie du document signé.</p>
            <div class="field">
                <label for="name">Nom et prénom *</label>
                <input id="name" type="text" name="name" value="{{ old('name', $attachment->client?->contactName() ?: $attachment->client?->displayName()) }}" required maxlength="160" autocomplete="{{ $onSite ? 'off' : 'name' }}">
            </div>
            <div class="field">
                <label>Signature *</label>
                <div class="signature-pad"><canvas data-signature-canvas aria-label="Zone de signature"></canvas></div>
                <button type="button" class="btn btn-secondary btn-sm" data-signature-clear>Effacer</button>
                <input type="hidden" name="signature" data-signature-input>
            </div>
            <label class="check"><input type="checkbox" name="agree" value="1" required>
                <span><strong>Lu et approuvé.</strong> J'ai pris connaissance du document « {{ $attachment->name }} » et je l'accepte.</span></label>
            <div class="form-actions"><button class="btn" type="submit"><x-icon name="check" /> Signer le document</button></div>
        </form>

        <script src="{{ asset('js/signature.js') }}?v={{ filemtime(public_path('js/signature.js')) }}" defer></script>
    @endunless
@endsection
