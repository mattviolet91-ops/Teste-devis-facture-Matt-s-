@extends('settings.layout', ['title' => 'Mon affichage'])

@php use App\Support\Navigation; @endphp

@section('settings')
<form method="POST" action="{{ route('settings.display') }}" data-display-form>
    @csrf
    @method('PUT')

    <div class="card">
        <h2>Barre du bas</h2>
        <p class="muted small">Choisissez les 3 raccourcis toujours visibles en bas de l'écran. Le bouton « + Nouveau » et le menu « Plus » restent en place.</p>
        @error('bottom.*')<div class="alert alert-error">{{ $message }}</div>@enderror
        <div class="display-preview bottom-nav-preview" aria-hidden="true">
            @foreach ($bottom as $i => $key)
                @if ($i === 2)<span class="fab-preview"><x-icon name="plus" /></span>@endif
                <span data-preview-slot="{{ $i }}"><x-icon :name="Navigation::ITEMS[$key][1]" /> <span>{{ Navigation::ITEMS[$key][0] }}</span></span>
            @endforeach
            <span><x-icon name="menu" /> <span>Plus</span></span>
        </div>
        <div class="form-grid cols-2" style="margin-top:1rem">
            @foreach ([0 => '1er raccourci (à gauche)', 1 => '2e raccourci', 2 => '3e raccourci (à droite du +)'] as $i => $label)
                <div class="field">
                    <label for="bottom-{{ $i }}">{{ $label }}</label>
                    <select id="bottom-{{ $i }}" name="bottom[{{ $i }}]" data-bottom-slot="{{ $i }}">
                        @foreach (Navigation::ITEMS as $key => [$itemLabel, $icon])
                            <option value="{{ $key }}" data-icon="{{ $icon }}" @selected(old('bottom.'.$i, $bottom[$i]) === $key)>{{ $itemLabel }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <h2>Page d'accueil</h2>
        <p class="muted small">Cochez les blocs à afficher et rangez-les avec les flèches. Les alertes importantes (assurance, nouvelles demandes, sauvegarde) restent toujours en haut.</p>
        @error('blocks')<div class="alert alert-error">{{ $message }}</div>@enderror
        <ul class="block-order" data-block-order>
            @foreach ($blocks as $key)
                <li data-block>
                    <input type="hidden" name="order[]" value="{{ $key }}">
                    <label class="check"><input type="checkbox" name="blocks[]" value="{{ $key }}" @checked(in_array($key, $enabled, true))> <span>{{ Navigation::HOME_BLOCKS[$key] }}</span></label>
                    <span class="block-moves">
                        <button type="button" class="icon-btn icon-btn-sm" data-move-block="up" title="Monter"><x-icon name="chevron-up" /><span class="visually-hidden">Monter</span></button>
                        <button type="button" class="icon-btn icon-btn-sm" data-move-block="down" title="Descendre"><x-icon name="chevron-down" /><span class="visually-hidden">Descendre</span></button>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="form-actions sticky-actions"><button class="btn" type="submit">Enregistrer</button></div>
</form>

<form method="POST" action="{{ route('settings.display.reset') }}" data-confirm="Remettre l'affichage par défaut ?">
    @csrf
    <div class="form-actions"><button class="btn btn-secondary" type="submit">Remettre par défaut</button></div>
</form>

<script src="{{ asset('js/display-settings.js') }}?v={{ filemtime(public_path('js/display-settings.js')) }}" defer></script>
@endsection
