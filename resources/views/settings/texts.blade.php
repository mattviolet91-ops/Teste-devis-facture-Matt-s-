@extends('settings.layout', ['title' => 'Textes types'])

@section('settings')
    @foreach ($groups as $type => $group)
        <div class="card">
            <h2>{{ $group['label'] }}</h2>
            <p class="muted small">
                @switch($type)
                    @case('payment_terms') Proposées dans l'éditeur de devis ; celle « par défaut » est préremplie. @break
                    @case('note') Ajoutées aux notes du devis en un clic ; celles « par défaut » sont préremplies. @break
                    @default Ajoutées au détail d'une prestation en un clic (« + Ajouter une étape type »).
                @endswitch
            </p>
            @foreach ($group['items'] as $template)
                <form method="POST" action="{{ route('settings.texts.update', $template) }}" class="text-row">
                    @csrf
                    @method('PUT')
                    <div class="field"><label for="label-{{ $template->id }}">Nom</label><input id="label-{{ $template->id }}" type="text" name="label" value="{{ $template->label }}" required></div>
                    <div class="field"><label for="body-{{ $template->id }}">Texte</label><textarea id="body-{{ $template->id }}" name="body" rows="2" required>{{ $template->body }}</textarea></div>
                    <div class="text-row-actions">
                        @unless ($type === 'step')
                            <label class="check small"><input type="checkbox" name="is_default" value="1" @checked($template->is_default)> <span>Par défaut</span></label>
                        @endunless
                        <button class="btn btn-secondary btn-sm" type="submit">Enregistrer</button>
                        <button class="btn btn-danger-outline btn-sm" type="submit" form="delete-text-{{ $template->id }}">Supprimer</button>
                    </div>
                </form>
                <form id="delete-text-{{ $template->id }}" method="POST" action="{{ route('settings.texts.destroy', $template) }}" data-confirm="Supprimer ce texte type ?">
                    @csrf
                    @method('DELETE')
                </form>
            @endforeach
            <details style="margin-top:1rem">
                <summary class="btn btn-secondary btn-sm">Ajouter</summary>
                <form method="POST" action="{{ route('settings.texts.store') }}" class="form-grid" style="margin-top:1rem">
                    @csrf
                    <input type="hidden" name="type" value="{{ $type }}">
                    <div class="field"><label for="new-label-{{ $type }}">Nom</label><input id="new-label-{{ $type }}" type="text" name="label" required></div>
                    <div class="field"><label for="new-body-{{ $type }}">Texte</label><textarea id="new-body-{{ $type }}" name="body" rows="2" required></textarea></div>
                    @unless ($type === 'step')
                        <label class="check"><input type="checkbox" name="is_default" value="1"> <span>Par défaut</span></label>
                    @endunless
                    <div><button class="btn btn-sm" type="submit">Ajouter</button></div>
                </form>
            </details>
        </div>
    @endforeach
@endsection
