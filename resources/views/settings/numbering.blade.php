@extends('settings.layout', ['title' => 'Numérotation'])

@section('settings')
<form method="POST" action="{{ route('settings.numbering') }}">
    @csrf
    @method('PUT')

    <div class="card">
        <h2>Numérotation des documents</h2>
        <p class="muted small">Format : PRÉFIXE-ANNÉE-NUMÉRO. Le numéro continue d'une année à l'autre, il ne repart jamais à zéro.
            Il est attribué au moment de l'envoi : un brouillon n'a pas de numéro.</p>

        @foreach ($sequences as $sequence)
            <div class="row-form" style="grid-template-columns: 1fr">
                <h3 style="margin:0">{{ \App\Models\NumberSequence::LABELS[$sequence->type] ?? $sequence->type }}</h3>
                <div class="form-grid cols-2">
                    <x-field :name="'sequences.'.$sequence->type.'.prefix'" label="Préfixe" :value="$sequence->prefix" maxlength="10" required />
                    <x-field :name="'sequences.'.$sequence->type.'.next_number'" label="Prochain numéro" type="number" min="1" :value="$sequence->next_number" required />
                </div>
                <p class="small muted" style="margin:0">Prochain document : <strong>{{ $sequence->format($sequence->next_number) }}</strong></p>
            </div>
        @endforeach
    </div>

    <div class="card">
        <h2>Devis</h2>
        <div class="form-grid cols-2">
            <x-field name="quote_validity_days" label="Durée de validité par défaut (jours)" type="number" min="1" max="365" :value="$documents['quote_validity_days']" hint="Modifiable sur chaque devis." required />
        </div>
    </div>

    <div class="form-actions">
        <button class="btn" type="submit">Enregistrer</button>
    </div>
</form>
@endsection
