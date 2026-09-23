{{-- Photos en annexe du PDF d'un devis ou d'une facture. Attend : $document, $route (nom de route de sélection). --}}
@php
    $isQuote = $document instanceof \App\Models\Quote;
    $uploadRoute = $isQuote ? 'quotes.photos.upload' : 'invoices.photos.upload';
    $available = \App\Models\Photo::query()->where('client_id', $document->client_id)
        ->when($document->worksite_id, fn ($q) => $q->where(fn ($w) => $w->where('worksite_id', $document->worksite_id)->orWhereNull('worksite_id')))
        ->latest('id')->get();
    $chosen = $document->photos->pluck('id')->all();
@endphp
@if ($document->photosEditable())
    <div class="card" id="photos">
        <div class="card-head">
            <h2>Photos dans le PDF</h2>
            <span class="muted small">{{ count($chosen) }} photo(s) en annexe</span>
        </div>
        @error('photos')<div class="alert alert-error">{{ $message }}</div>@enderror
        @error('photos.*')<div class="alert alert-error">{{ $message }}</div>@enderror

        <form method="POST" action="{{ route($uploadRoute, $document) }}" enctype="multipart/form-data" data-photo-upload>
            @csrf
            <div class="form-grid cols-2">
                <div class="field">
                    <label for="doc-photo-category">Catégorie</label>
                    <select id="doc-photo-category" name="category">
                        @foreach (\App\Models\Photo::CATEGORIES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="doc-photo-caption">Légende (facultatif)</label>
                    <input id="doc-photo-caption" type="text" name="caption" maxlength="255" placeholder="ex. Faîtage côté rue">
                </div>
                <div class="field span-2">
                    <label class="btn photo-pick" for="doc-photo-files"><x-icon name="camera" /> Prendre ou ajouter des photos</label>
                    <input id="doc-photo-files" class="visually-hidden" type="file" name="photos[]" accept="image/*" multiple data-photo-input>
                </div>
            </div>
            <p class="small" data-photo-status role="status" aria-live="polite"></p>
            <noscript><button class="btn" type="submit">Envoyer</button></noscript>
        </form>

        @if ($available->isNotEmpty())
            <form method="POST" action="{{ route($route, $document) }}" style="margin-top:.75rem">
                @csrf
                <p class="small muted" style="margin:0 0 .5rem">Cochez les photos à imprimer en fin de PDF :</p>
                <div class="photo-pick-list">
                    @foreach ($available as $photo)
                        <label title="{{ $photo->caption ?: $photo->categoryLabel() }}">
                            <input type="checkbox" name="photos[]" value="{{ $photo->id }}" @checked(in_array($photo->id, $chosen, true))>
                            <img src="{{ route('photos.file', [$photo, 'mini']) }}" alt="{{ $photo->caption ?: $photo->categoryLabel() }}" loading="lazy">
                        </label>
                    @endforeach
                </div>
                <div class="form-actions"><button class="btn btn-secondary" type="submit">Enregistrer la sélection</button></div>
            </form>
        @endif
        @if (! $document->isDraft())
            <p class="small muted">Le PDF envoyé au client est mis à jour avec les photos.</p>
        @endif
    </div>
    <script src="{{ asset('js/photos.js') }}?v={{ filemtime(public_path('js/photos.js')) }}" defer></script>
@elseif ($document->photos->isNotEmpty())
    <div class="card">
        <h2>Photos dans le PDF</h2>
        <div class="photo-strip">
            @foreach ($document->photos as $photo)
                <img src="{{ route('photos.file', [$photo, 'mini']) }}" alt="{{ $photo->caption ?: $photo->categoryLabel() }}" loading="lazy">
            @endforeach
        </div>
    </div>
@endif
