{{-- Photos en annexe du PDF d'un devis ou d'une facture. Attend : $document, $route (nom de route de sélection). --}}
@php
    $available = $document->worksite
        ? $document->worksite->photos()->get()
        : \App\Models\Photo::query()->whereHas('worksite', fn ($q) => $q->where('client_id', $document->client_id))->latest('id')->get();
    $chosen = $document->photos->pluck('id')->all();
@endphp
@if ($document->isDraft() && $available->isNotEmpty())
    <details class="card" @if ($chosen) open @endif>
        <summary><strong>Photos en annexe du PDF</strong> <span class="muted small">({{ count($chosen) }} sur {{ $available->count() }})</span></summary>
        <form method="POST" action="{{ route($route, $document) }}" style="margin-top:.75rem">
            @csrf
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
    </details>
@elseif (! $document->isDraft() && $document->photos->isNotEmpty())
    <div class="card">
        <h2>Photos en annexe du PDF</h2>
        <div class="photo-strip">
            @foreach ($document->photos as $photo)
                <img src="{{ route('photos.file', [$photo, 'mini']) }}" alt="{{ $photo->caption ?: $photo->categoryLabel() }}" loading="lazy">
            @endforeach
        </div>
    </div>
@elseif ($document->isDraft() && $document->worksite)
    <p class="small muted">Pour joindre des photos au PDF : <a href="{{ route('photos.worksite', $document->worksite) }}">ajoutez-les au chantier</a>.</p>
@endif
