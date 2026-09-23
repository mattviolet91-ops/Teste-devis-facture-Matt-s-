{{-- Envoi de photos (compressées dans le téléphone avant l'envoi, mises en attente hors connexion). --}}
<form method="POST" action="{{ route('photos.store', $worksite) }}" enctype="multipart/form-data" class="card photo-upload" data-photo-upload>
    @csrf
    <h2>Ajouter des photos</h2>
    <div class="form-grid cols-2">
        <div class="field">
            <label for="category-{{ $worksite->id }}">Catégorie</label>
            <select id="category-{{ $worksite->id }}" name="category">
                @foreach (\App\Models\Photo::CATEGORIES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="caption-{{ $worksite->id }}">Légende (facultatif)</label>
            <input id="caption-{{ $worksite->id }}" type="text" name="caption" maxlength="255" placeholder="ex. Faîtage côté rue">
        </div>
        <div class="field span-2">
            <label class="btn photo-pick" for="files-{{ $worksite->id }}"><x-icon name="camera" /> Prendre ou choisir des photos</label>
            <input id="files-{{ $worksite->id }}" class="visually-hidden" type="file" name="photos[]" accept="image/*" multiple data-photo-input>
            <span class="hint">Les photos sont réduites avant l'envoi. Sans réseau, elles attendent et partent dès le retour de la connexion.</span>
        </div>
    </div>
    <p class="small" data-photo-status role="status" aria-live="polite"></p>
    <noscript><button class="btn" type="submit">Envoyer</button></noscript>
</form>
