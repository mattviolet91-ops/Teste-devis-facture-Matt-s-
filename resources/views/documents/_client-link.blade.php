{{-- Lien client d'un document envoyé. Attend : $document. --}}
@php
    $isQuote = $document instanceof \App\Models\Quote;
    $url = $document->publicUrl();
    $share = rawurlencode(($isQuote ? 'Votre devis ' : 'Votre facture ').$document->number.' : '.$url);
@endphp
<div class="card">
    <div class="card-head">
        <h2>Lien client</h2>
        @if ($document->viewed_at)
            <span class="badge badge-info">Consulté le {{ $document->viewed_at->format('d/m/Y à H:i') }}</span>
        @else
            <span class="badge">Pas encore consulté</span>
        @endif
    </div>
    <p class="small muted">{{ $isQuote ? 'Le client consulte le devis, le télécharge et peut l\'accepter en signant sur son téléphone.' : 'Le client consulte et télécharge sa facture.' }} Le lien est inclus automatiquement dans les emails envoyés depuis l'application.</p>
    <div class="copy-field">
        <input type="text" value="{{ $url }}" readonly aria-label="Lien client" data-copy-source>
        <button class="btn btn-secondary btn-sm" type="button" data-copy>Copier</button>
    </div>
    <div class="chips" style="margin-top:.75rem">
        <a class="chip" href="{{ $url }}" target="_blank" rel="noopener">Ouvrir</a>
        <a class="chip" href="https://wa.me/?text={{ $share }}" target="_blank" rel="noopener"><x-icon name="message" /> WhatsApp</a>
        <a class="chip" href="sms:{{ $document->client?->phone ? preg_replace('/\s/', '', $document->client->phone) : '' }}?&body={{ $share }}"><x-icon name="message" /> SMS</a>
    </div>

    @if ($isQuote && $document->isSigned())
        <div class="signature-proof">
            <img src="{{ route('quotes.signature', $document) }}" alt="Signature du client">
            <p class="small">Signé en ligne par <strong>{{ $document->signed_name }}</strong> le {{ $document->signed_at->format('d/m/Y à H:i:s') }} — IP {{ $document->signed_ip }}</p>
        </div>
    @endif
    @if ($isQuote && $document->change_requested_at)
        <div class="alert alert-warning" style="margin-top:.75rem">
            <strong>Demande de modification du client ({{ $document->change_requested_at->format('d/m/Y') }}) :</strong><br>
            <span class="pre-line">{{ $document->client_comment }}</span>
        </div>
    @endif
</div>
