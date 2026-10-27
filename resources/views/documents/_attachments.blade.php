{{--
    Documents d'un devis ou d'une facture : scan (photos → un PDF) ou fichiers.
    Attend : $document (Quote ou Invoice).
--}}
@php
    $isQuote = $document instanceof \App\Models\Quote;
    $action = $isQuote ? route('attachments.quote.store', $document) : route('attachments.invoice.store', $document);
    $attachments = $document->attachments;
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
        <p class="muted small" style="margin-top:0">Bon de commande signé, plan, courrier, ticket, attestation… Scannez-le avec le téléphone ou ajoutez un fichier : il est rangé ici et sur la fiche du client.</p>
    @else
        <ul class="stat-list">
            @foreach ($attachments as $attachment)
                <li>
                    @if ($attachment->mime === 'application/pdf')
                        <a href="{{ \App\Http\Controllers\PdfViewerController::link(route('attachments.show', $attachment), $attachment->name) }}">{{ $attachment->name }}</a>
                    @else
                        <a href="{{ route('attachments.show', $attachment) }}" target="_blank" rel="noopener">{{ $attachment->name }}</a>
                    @endif
                    <span class="muted small">{{ $attachment->humanSize() }} · {{ $attachment->created_at->format('d/m/Y') }}
                        <form method="POST" action="{{ route('attachments.destroy', $attachment) }}" data-confirm="Supprimer « {{ $attachment->name }} » ?" style="display:inline">
                            @csrf
                            @method('DELETE')
                            <button class="link-danger" type="submit">Supprimer</button>
                        </form>
                    </span>
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
                <label for="scan-pages-{{ $document->id }}">Pages du document *</label>
                {{-- Champ envoyé : rempli page après page par le script (sans script : choix direct des photos). --}}
                <input id="scan-pages-{{ $document->id }}" type="file" name="pages[]" accept="image/*" multiple required data-scan-pages>
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
        <label class="btn btn-secondary btn-sm" for="doc-files-{{ $document->id }}"><x-icon name="plus" /> Ajouter un fichier</label>
        <input id="doc-files-{{ $document->id }}" class="visually-hidden" type="file" name="files[]" multiple data-autosubmit
            accept="application/pdf,image/*,.doc,.docx,.xls,.xlsx,.odt,.ods,.txt">
    </form>
</div>
