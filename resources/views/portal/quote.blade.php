@extends('layouts.portal', ['title' => 'Devis '.$quote->number])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Devis {{ $quote->number }}</h1>
            <p>{{ $quote->title ?: 'Votre devis' }} — {{ Money::format($quote->total_ttc) }}</p>
        </div>
        <a class="btn btn-secondary" href="{{ route('portal.quote.pdf', $quote->public_token) }}" target="_blank" rel="noopener"><x-icon name="file" /> Télécharger le PDF</a>
    </div>

    @if ($quote->isSigned())
        <div class="card portal-status portal-ok">
            <h2><x-icon name="check" /> Devis accepté</h2>
            <p>Accepté et signé par <strong>{{ $quote->signed_name }}</strong> le {{ $quote->signed_at->format('d/m/Y à H:i') }}. Merci pour votre confiance !
                Nous vous recontactons rapidement pour organiser l'intervention.</p>
        </div>
    @elseif ($quote->status === 'accepted')
        <div class="card portal-status portal-ok"><h2><x-icon name="check" /> Devis accepté</h2><p>Merci pour votre confiance.</p></div>
    @elseif ($quote->status === 'refused')
        <div class="card portal-status"><h2>Devis refusé</h2><p>Votre réponse a été enregistrée. N'hésitez pas à nous recontacter.</p></div>
    @elseif ($quote->status === 'replaced')
        <div class="card portal-status"><h2>Devis remplacé</h2><p>Une nouvelle version de ce devis vous a été adressée. Utilisez le lien reçu avec la nouvelle version.</p></div>
    @elseif (! $quote->canBeSignedOnline())
        <div class="card portal-status"><h2>Devis expiré</h2><p>La validité de ce devis est dépassée. Contactez-nous pour une mise à jour.</p></div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error" role="alert"><ul class="error-list">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <article class="doc">
        @include('documents._preview', [
            'document' => $quote,
            'docTitle' => 'Devis '.$quote->number,
            'meta' => array_filter(['Émis le '.$quote->issue_date?->format('d/m/Y'), $quote->valid_until ? 'Valable jusqu\'au '.$quote->valid_until->format('d/m/Y') : null]),
        ])
        <div class="doc-footer">
            @if ($quote->work_start || $quote->work_duration)
                <p><strong>Travaux :</strong> {{ collect([$quote->work_start ? 'début prévu '.$quote->work_start : null, $quote->work_duration ? 'durée estimée '.$quote->work_duration : null])->filter()->implode(' — ') }}</p>
            @endif
            @if ($quote->payment_terms)<p><strong>Conditions de paiement :</strong> {{ $quote->payment_terms }}</p>@endif
            @if ($quote->notes)<p>{!! nl2br(e($quote->notes)) !!}</p>@endif
            @include('documents._mentions', ['document' => $quote])
            <p class="small">Conditions générales de vente et mentions complètes : voir le PDF.</p>
        </div>
    </article>

    @if ($quote->canBeSignedOnline() && ! $quote->isSigned())
        <form method="POST" action="{{ route('portal.quote.sign', $quote->public_token) }}" class="card" id="accepter" data-signature-form>
            @csrf
            <h2>Accepter le devis</h2>
            <p class="muted small">Montant : <strong>{{ Money::format($quote->total_ttc) }}</strong>. Votre signature électronique a la même valeur qu'une signature sur papier.
                La date, l'heure et l'adresse IP de connexion sont enregistrées.</p>
            <div class="field">
                <label for="name">Nom et prénom *</label>
                <input id="name" type="text" name="name" value="{{ old('name', $quote->client?->contactName() ?: $quote->client?->displayName()) }}" required maxlength="160" autocomplete="name">
            </div>
            <div class="field">
                <label>Signature *</label>
                <div class="signature-pad"><canvas data-signature-canvas aria-label="Zone de signature"></canvas></div>
                <button type="button" class="btn btn-secondary btn-sm" data-signature-clear>Effacer</button>
                <input type="hidden" name="signature" data-signature-input>
            </div>
            <label class="check"><input type="checkbox" name="agree" value="1" required>
                <span><strong>Bon pour accord.</strong> J'accepte le devis n° {{ $quote->number }} et les conditions générales de vente.</span></label>
            <div class="form-actions"><button class="btn" type="submit"><x-icon name="check" /> J'accepte le devis</button></div>
        </form>

        <details class="card">
            <summary><strong>Demander une modification</strong></summary>
            <form method="POST" action="{{ route('portal.quote.change', $quote->public_token) }}" style="margin-top:.75rem">
                @csrf
                <div class="field">
                    <label for="change">Votre demande</label>
                    <textarea id="change" name="comment" rows="4" required maxlength="2000" placeholder="ex. Pouvez-vous ajouter le nettoyage des gouttières ?"></textarea>
                </div>
                <div class="form-actions"><button class="btn btn-secondary" type="submit">Envoyer ma demande</button></div>
            </form>
        </details>

        <details class="card">
            <summary><strong>Refuser le devis</strong></summary>
            <form method="POST" action="{{ route('portal.quote.refuse', $quote->public_token) }}" style="margin-top:.75rem" data-confirm="Confirmer le refus de ce devis ?">
                @csrf
                <div class="field">
                    <label for="refuse">Motif (facultatif)</label>
                    <textarea id="refuse" name="comment" rows="3" maxlength="2000"></textarea>
                </div>
                <div class="form-actions"><button class="btn btn-danger-outline" type="submit">Refuser le devis</button></div>
            </form>
        </details>
    @endif

    <script src="{{ asset('js/signature.js') }}?v={{ filemtime(public_path('js/signature.js')) }}" defer></script>
@endsection
