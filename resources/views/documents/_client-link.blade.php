{{-- Lien client d'un document envoyé, et message prêt à envoyer par SMS / WhatsApp. Attend : $document. --}}
@php
    $isQuote = $document instanceof \App\Models\Quote;
    $url = $document->publicUrl();
    $message = app(\App\Services\EmailComposer::class)->renderText(
        (string) $settings->get($isQuote ? 'mail.sms_quote' : 'mail.sms_invoice'),
        $document->client,
        $document,
    );
    $phone = preg_replace('/\D/', '', (string) $document->client?->phone);
    $whatsappPhone = preg_match('/^0\d{9}$/', $phone) ? '33'.substr($phone, 1) : $phone;
@endphp
<div class="card" data-share>
    <div class="card-head">
        <h2>Envoyer au client</h2>
        @if ($document->viewed_at)
            <span class="badge badge-info">Consulté le {{ $document->viewed_at->format('d/m/Y à H:i') }}</span>
        @else
            <span class="badge">Pas encore consulté</span>
        @endif
    </div>
    <p class="small muted">{{ $isQuote ? 'Le client consulte le devis, le télécharge et peut l\'accepter en signant sur son téléphone.' : 'Le client consulte et télécharge sa facture.' }}
        Message modifiable ci-dessous avant l'envoi (modèle dans <a href="{{ route('settings.emails') }}#sms">Réglages → Emails</a>).</p>

    <div class="field">
        <label for="share-message-{{ $document->id }}">Message</label>
        <textarea id="share-message-{{ $document->id }}" rows="7" data-share-message>{{ $message }}</textarea>
    </div>
    <div class="chips" style="margin-top:.5rem">
        <a class="chip" href="#" data-share-to="whatsapp" data-phone="{{ $whatsappPhone }}"><x-icon name="message" /> WhatsApp</a>
        <a class="chip" href="#" data-share-to="sms" data-phone="{{ $phone }}"><x-icon name="message" /> SMS</a>
        <button class="chip" type="button" data-share-to="copy"><x-icon name="copy" /> Copier le message</button>
    </div>

    <div class="copy-field" style="margin-top:.75rem">
        <input type="text" value="{{ $url }}" readonly aria-label="Lien client" data-copy-source>
        <button class="btn btn-secondary btn-sm" type="button" data-copy>Copier le lien</button>
        <a class="btn btn-secondary btn-sm" href="{{ $url }}" target="_blank" rel="noopener">Ouvrir</a>
    </div>

    @if ($isQuote && $document->isSigned())
        <div class="signature-proof">
            <img src="{{ route('quotes.signature', $document) }}" alt="Signature du client">
            <p class="small">Signé {{ $document->signed_on_site ? 'sur place' : 'en ligne' }} par <strong>{{ $document->signed_name }}</strong> le {{ $document->signed_at->format('d/m/Y à H:i:s') }}{{ $document->signed_on_site ? '' : ' — IP '.$document->signed_ip }}</p>
        </div>
    @endif
    @if ($isQuote && $document->change_requested_at)
        <div class="alert alert-warning" style="margin-top:.75rem">
            <strong>Demande de modification du client ({{ $document->change_requested_at->format('d/m/Y') }}) :</strong><br>
            <span class="pre-line">{{ $document->client_comment }}</span>
        </div>
    @endif
</div>
