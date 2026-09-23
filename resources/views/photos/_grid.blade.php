{{-- Grille de photos. Attend : $photos ; $manage (bool) pour les actions. --}}
<div class="photo-grid">
    @foreach ($photos as $photo)
        <figure class="photo-tile" id="photo-{{ $photo->id }}">
            <a href="{{ route('photos.file', [$photo, 'photo']) }}" target="_blank" rel="noopener">
                <img src="{{ route('photos.file', [$photo, 'mini']) }}?v={{ $photo->updated_at?->timestamp }}" alt="{{ $photo->caption ?: $photo->categoryLabel() }}" loading="lazy">
            </a>
            <figcaption>
                <span class="badge">{{ $photo->categoryLabel() }}</span>
                @if ($photo->annotated_path)<span class="badge badge-info">Annotée</span>@endif
                @if ($photo->caption)<span class="small">{{ $photo->caption }}</span>@endif
                @unless ($manage ?? false)
                    <span class="muted small">{{ $photo->client?->displayName() }}</span>
                @endunless
            </figcaption>
            @if ($manage ?? false)
                <details class="photo-edit">
                    <summary class="small">Modifier</summary>
                    <form method="POST" action="{{ route('photos.update', $photo) }}">
                        @csrf
                        @method('PUT')
                        <select name="category" aria-label="Catégorie">
                            @foreach (\App\Models\Photo::CATEGORIES as $key => $label)
                                <option value="{{ $key }}" @selected($photo->category === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="caption" value="{{ $photo->caption }}" placeholder="Légende" maxlength="255" aria-label="Légende">
                        <button class="btn btn-sm" type="submit">Enregistrer</button>
                    </form>
                    <button class="btn btn-secondary btn-sm" type="button" data-annotate="{{ route('photos.file', [$photo, 'original']) }}" data-annotate-action="{{ route('photos.annotate', $photo) }}">Annoter</button>
                    @if ($photo->annotated_path)
                        <form method="POST" action="{{ route('photos.annotate', $photo) }}">
                            @csrf
                            <input type="hidden" name="remove" value="1">
                            <button class="btn btn-secondary btn-sm" type="submit">Retirer l'annotation</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('photos.destroy', $photo) }}" data-confirm="Supprimer cette photo définitivement ?">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-danger-outline btn-sm" type="submit">Supprimer</button>
                    </form>
                </details>
            @endif
        </figure>
    @endforeach
</div>
