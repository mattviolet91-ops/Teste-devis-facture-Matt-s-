<dialog class="sheet sheet-tall annotator" id="annotator" aria-labelledby="annotator-title">
    <div class="card-head">
        <h2 id="annotator-title">Annoter la photo</h2>
        <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
    </div>
    <div class="annotator-tools">
        @foreach (['#E53935' => 'Rouge', '#FDD835' => 'Jaune', '#FFFFFF' => 'Blanc', '#3CBDE8' => 'Bleu'] as $color => $label)
            <button type="button" class="swatch" data-color="{{ $color }}" style="background: {{ $color }}" title="{{ $label }}"><span class="visually-hidden">{{ $label }}</span></button>
        @endforeach
        <button type="button" class="btn btn-secondary btn-sm" data-annotate-undo>Annuler le trait</button>
    </div>
    <div class="annotator-canvas"><canvas data-annotate-canvas></canvas></div>
    <p class="small muted">Dessinez avec le doigt pour entourer ou montrer un défaut. La photo d'origine est conservée.</p>
    <div class="form-actions"><button class="btn" type="button" data-annotate-save>Enregistrer l'annotation</button></div>
</dialog>
<script src="{{ asset('js/photos.js') }}?v={{ filemtime(public_path('js/photos.js')) }}" defer></script>
