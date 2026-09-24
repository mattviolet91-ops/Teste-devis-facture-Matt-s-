@extends('layouts.portal', ['title' => 'Signature du devis'])

@php use App\Support\Money; @endphp

{{-- Écran tendu au client pendant le rendez-vous : pas de menu de gestion. --}}
@section('content')
    <p><a class="small" href="{{ route('quotes.show', $quote) }}">← Retour au devis (réservé à l'entreprise)</a></p>

    <div class="page-head">
        <div>
            <h1>Devis {{ $quote->number ?? '' }}</h1>
            <p>{{ $quote->title ?: 'Votre devis' }} — {{ Money::format($quote->total_ttc) }}</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert"><ul class="error-list">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <details class="card">
        <summary><strong>Voir le détail du devis</strong></summary>
        <article class="doc" style="margin-top:.75rem">
            @include('documents._preview', [
                'document' => $quote,
                'docTitle' => 'Devis '.($quote->number ?? ''),
                'meta' => array_filter([$quote->issue_date ? 'Émis le '.$quote->issue_date->format('d/m/Y') : 'Émis le '.today()->format('d/m/Y')]),
            ])
            <div class="doc-footer">
                @if ($quote->payment_terms)<p><strong>Conditions de paiement :</strong> {{ $quote->payment_terms }}</p>@endif
                @if ($quote->notes)<p>{!! nl2br(e($quote->notes)) !!}</p>@endif
                @include('documents._mentions', ['document' => $quote])
            </div>
        </article>
    </details>

    <form method="POST" action="{{ route('quotes.on-site.sign', $quote) }}" class="card" data-signature-form>
        @csrf
        <h2>Accepter le devis</h2>
        <p class="muted small">Montant : <strong>{{ Money::format($quote->total_ttc) }}</strong>. Votre signature électronique a la même valeur qu'une signature sur papier.
            La date et l'heure sont enregistrées. Vous recevrez une copie du devis signé.</p>
        <div class="field">
            <label for="name">Nom et prénom *</label>
            <input id="name" type="text" name="name" value="{{ old('name', $quote->client?->contactName() ?: $quote->client?->displayName()) }}" required maxlength="160" autocomplete="off">
        </div>
        <div class="field">
            <label>Signature *</label>
            <div class="signature-pad"><canvas data-signature-canvas aria-label="Zone de signature"></canvas></div>
            <button type="button" class="btn btn-secondary btn-sm" data-signature-clear>Effacer</button>
            <input type="hidden" name="signature" data-signature-input>
        </div>
        <label class="check"><input type="checkbox" name="agree" value="1" required>
            <span><strong>Bon pour accord.</strong> J'accepte le devis{{ $quote->number ? ' n° '.$quote->number : '' }} et les conditions générales de vente.</span></label>
        <div class="form-actions"><button class="btn" type="submit"><x-icon name="check" /> J'accepte le devis</button></div>
    </form>

    <script src="{{ asset('js/signature.js') }}?v={{ filemtime(public_path('js/signature.js')) }}" defer></script>
@endsection
