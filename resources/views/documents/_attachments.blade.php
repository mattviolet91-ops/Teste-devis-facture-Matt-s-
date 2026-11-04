{{--
    Documents d'un devis, d'une facture ou d'une fiche client : scan (photos → un PDF), fichiers,
    et signature du client (sur place ou par un lien).
    Attend : $document (Quote, Invoice ou Client).
--}}
@php
    $isClient = $document instanceof \App\Models\Client;
    $isQuote = $document instanceof \App\Models\Quote;
    $client = $isClient ? $document : $document->client;
    $action = match (true) {
        $isClient => route('attachments.store', $document),
        $isQuote => route('attachments.quote.store', $document),
        default => route('attachments.invoice.store', $document),
    };
    $attachments = $isClient
        ? $document->attachments->reject(fn ($a) => $a->invoice_id && ! auth()->user()->isAdmin())
        : $document->attachments;
    $phone = preg_replace('/\D/', '', (string) $client?->phone);
    $whatsappPhone = preg_match('/^0\d{9}$/', $phone) ? '33'.substr($phone, 1) : $phone;
    $uid = ($isClient ? 'c' : ($isQuote ? 'q' : 'i')).$document->id;
@endphp
<div class="card" id="documents">
    <div class="card-head">
        <h2>Documents</h2>
        <span class="muted small">{{ $attachments->count() }} document(s)</span>
    </div>
    @error('pages')<div class="alert alert-error">{{ $message }}</div>@enderror
    @error('pages.*')<div class="alert alert-error">{{ $message }}</div>@enderror
    @error('files')<div class="alert alert-error">{{ $message }}</div>@enderror
    @error('files.*')<div class="alert alert-error">{{ $message }}</div>@enderror

    @if ($attachments->isEmpty())
        <p class="muted small" style="margin-top:0">{{ $isClient ? 'Plan, courrier, attestation, bon de commande…' : 'Bon de commande, plan, courrier, ticket, attestation…' }}
            Scannez-le avec le téléphone ou ajoutez un fichier{{ $isClient ? '' : ' : il est rangé ici et sur la fiche du client' }}. Un PDF ou une photo peut être signé par le client, sur place ou en ligne.</p>
    @else
        <ul class="attachment-list">
            @foreach ($attachments as $attachment)
                <li>
                    <div>
                        @if ($attachment->currentMime() === 'application/pdf')
                            <a href="{{ \App\Http\Controllers\PdfViewerController::link(route('attachments.show', $attachment), $attachment->currentName()) }}">{{ $attachment->name }}</a>
                        @else
                            <a href="{{ route('attachments.show', $attachment) }}" target="_blank" rel="noopener">{{ $attachment->name }}</a>
                        @endif
                        @if ($attachment->isSigned())
                            <span class="badge badge-success">Signé</span>
                        @elseif ($attachment->awaitsSignature())
                            <span class="badge badge-warning">À signer{{ $attachment->viewed_at ? ' · ouvert le '.$attachment->viewed_at->format('d/m') : '' }}</span>
                        @endif
                    </div>
                    <span class="muted small">@if ($isClient && $attachment->quote)<a href="{{ route('quotes.show', $attachment->quote) }}">devis {{ $attachment->quote->number ?? '(brouillon)' }}</a> · @elseif ($isClient && $attachment->invoice)<a href="{{ route('invoices.show', $attachment->invoice) }}">facture {{ $attachment->invoice->number ?? '(brouillon)' }}</a> · @endif{{ $attachment->humanSize() }} · {{ $attachment->created_at->format('d/m/Y') }}
                        <form method="POST" action="{{ route('attachments.destroy', $attachment) }}" data-confirm="{{ $attachment->isSigned() ? 'Ce document est signé par le client. Supprimer quand même « '.$attachment->name.' » et sa signature ?' : 'Supprimer « '.$attachment->name.' » ?' }}" style="display:inline">
                            @csrf
                            @method('DELETE')
                            <button class="link-danger" type="submit">Supprimer</button>
                        </form>
                    </span>
                    @error('signature_'.$attachment->id)<div class="alert alert-error" style="margin:.5rem 0 0">{{ $message }}</div>@enderror

                    @if ($attachment->isSigned())
                        <p class="small" style="margin:.375rem 0 0">Signé {{ $attachment->signed_on_site ? 'sur place' : 'en ligne' }} par <strong>{{ $attachment->signed_name }}</strong>
                            le {{ $attachment->signed_at->format('d/m/Y à H:i') }}{{ $attachment->signed_ip ? ' — IP '.$attachment->signed_ip : '' }}.
                            <a href="{{ route('attachments.show', [$attachment, 'original' => 1]) }}" target="_blank" rel="noopener">Document d'origine</a>
                            @if ($attachment->public_token) · <a href="{{ $attachment->publicUrl() }}" target="_blank" rel="noopener">page du client</a>@endif</p>
                    @elseif ($attachment->awaitsSignature())
                        @php
                            $shareText = "Bonjour,\n\nVoici le document « {$attachment->name} » à lire et signer en ligne :\n{$attachment->publicUrl()}\n\nMerci,\n".$settings->get('company.trade_name');
                        @endphp
                        <div class="attachment-sign" data-share>
                            <div class="chips chips-wrap">
                                <a class="btn btn-sm" href="{{ route('attachments.sign.on-site', $attachment) }}"><x-icon name="pen" /> Faire signer sur place</a>
                                <a class="chip" href="#" data-share-to="whatsapp" data-phone="{{ $whatsappPhone }}"><x-icon name="message" /> WhatsApp</a>
                                <a class="chip" href="#" data-share-to="sms" data-phone="{{ $phone }}"><x-icon name="message" /> SMS</a>
                                <button class="chip" type="button" data-share-to="copy"><x-icon name="copy" /> Copier le message</button>
                            </div>
                            <details style="margin-top:.5rem">
                                <summary class="small">Modifier le message ou copier le lien</summary>
                                <textarea rows="6" aria-label="Message au client" data-share-message style="margin-top:.5rem">{{ $shareText }}</textarea>
                                <div class="copy-field" style="margin-top:.5rem">
                                    <input type="text" value="{{ $attachment->publicUrl() }}" readonly aria-label="Lien de signature" data-copy-source>
                                    <button class="btn btn-secondary btn-sm" type="button" data-copy>Copier</button>
                                </div>
                            </details>
                            <form method="POST" action="{{ route('attachments.sign.cancel', $attachment) }}" data-confirm="Annuler la demande de signature ? Le lien envoyé ne fonctionnera plus." style="margin-top:.375rem">
                                @csrf
                                @method('DELETE')
                                <button class="link-danger" type="submit" style="margin-left:0">Annuler la demande de signature</button>
                            </form>
                        </div>
                    @elseif ($attachment->canBeSigned())
                        <div class="chips chips-wrap attachment-sign">
                            <a class="chip" href="{{ route('attachments.sign.on-site', $attachment) }}"><x-icon name="pen" /> Faire signer sur place</a>
                            <form method="POST" action="{{ route('attachments.sign.request', $attachment) }}" style="display:inline">
                                @csrf
                                <button class="chip" type="submit"><x-icon name="send" /> Faire signer en ligne</button>
                            </form>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <details style="margin-top:.75rem" @if ($errors->hasAny(['pages', 'pages.*', 'scan_name'])) open @endif>
        <summary class="btn"><x-icon name="camera" /> Scanner un document</summary>
        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="form-grid" style="margin-top:.75rem">
            @csrf
            <input type="hidden" name="mode" value="scan">
            <x-field name="scan_name" label="Nom du document" placeholder="ex. Bon de commande signé" />
            <div class="field" data-scan>
                <label for="scan-pages-{{ $uid }}">Pages du document *</label>
                {{-- Champ envoyé : rempli page après page par le script (sans script : choix direct des photos). --}}
                <input id="scan-pages-{{ $uid }}" type="file" name="pages[]" accept="image/*" multiple required data-scan-pages>
                <input type="file" accept="image/*" capture="environment" hidden data-scan-camera>
                <div class="scan-thumbs" data-scan-thumbs></div>
                <div class="chips chips-wrap" data-scan-buttons hidden>
                    <button type="button" class="btn btn-secondary btn-sm" data-scan-take><x-icon name="camera" /> Photographier une page</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-scan-pick>Choisir dans les photos</button>
                </div>
                <span class="hint">Posez la feuille à plat, bien éclairée, et cadrez-la en entier. Une photo par page : elles deviennent un seul PDF.</span>
            </div>
            <div><button class="btn" type="submit"><x-icon name="check" /> Créer le PDF et l'ajouter</button></div>
        </form>
    </details>

    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="inline-upload" style="margin-top:.5rem">
        @csrf
        <label class="btn btn-secondary btn-sm" for="doc-files-{{ $uid }}"><x-icon name="plus" /> {{ $isClient ? 'Ajouter un document' : 'Ajouter un fichier' }}</label>
        <input id="doc-files-{{ $uid }}" class="visually-hidden" type="file" name="files[]" multiple data-autosubmit
            accept="application/pdf,image/*,.doc,.docx,.xls,.xlsx,.odt,.ods,.txt">
    </form>
</div>
