@extends('layouts.app', ['title' => $report->exists ? 'Modifier le rapport' : 'Rapport d\'intervention'])

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $report->exists ? 'Modifier le rapport' : 'Rapport d\'intervention' }}</h1>
            <p>{{ $client->displayName() }} · constat, travaux et préconisations, avec photos. Les photos s'ajoutent à l'étape suivante.</p>
        </div>
    </div>

    <form method="POST" action="{{ $report->exists ? route('reports.update', $report) : route('reports.store') }}" class="card">
        @csrf
        @if ($report->exists) @method('PUT') @endif
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="intervention_id" value="{{ $report->intervention_id }}">
        <div class="form-grid cols-2">
            <x-field name="title" label="Objet" :value="$report->title" required placeholder="ex. Recherche de fuite" class="span-2" />
            <x-field name="visit_date" label="Date de l'intervention" type="date" :value="$report->visit_date?->toDateString()" required />
            @php $worksites = $client->worksites()->get(); @endphp
            @if ($worksites->isNotEmpty())
                <x-select name="worksite_id" label="Lieu" :options="$worksites->mapWithKeys(fn ($w) => [$w->id => $w->label ?: $w->fullAddress()])->all()" :value="$report->worksite_id" placeholder="Adresse du client" />
            @endif
            <x-field name="findings" label="Constat" type="textarea" rows="6" :value="$report->findings" required class="span-2"
                placeholder="ex. Infiltration au droit de la souche de cheminée : solin fissuré, 3 tuiles cassées côté nord, traces d'humidité sur la charpente." />
            <x-field name="work_done" label="Travaux réalisés" type="textarea" rows="5" :value="$report->work_done" class="span-2"
                placeholder="ex. Bâchage de mise en sécurité, remplacement des 3 tuiles, reprise provisoire du solin." />
            <x-field name="recommendations" label="Préconisations" type="textarea" rows="5" :value="$report->recommendations" class="span-2"
                placeholder="ex. Réfection complète du solin en zinc à prévoir (devis à suivre). Contrôle de la charpente après séchage." />
        </div>
        <p class="hint">Laissez une ligne vide entre deux paragraphes.</p>
        <div class="action-bar">
            <button class="btn" type="submit"><x-icon name="check" /> {{ $report->exists ? 'Enregistrer' : 'Enregistrer et ajouter les photos' }}</button>
            <a class="btn btn-secondary" href="{{ $report->exists ? route('reports.show', $report) : route('clients.show', $client) }}">Annuler</a>
        </div>
    </form>
@endsection
