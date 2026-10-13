{{--
    Chantier d'une facture sans devis (frais et suivi). Par défaut : le dernier chantier du client
    à la même adresse ; « Nouveau chantier » sinon. Attend : $invoice, $clients.
--}}
@php
    app(\App\Services\JobCostService::class)->ensureProjects();
    $projectsByClient = \App\Models\Project::query()
        ->whereIn('client_id', $clients->pluck('id'))
        ->where(fn ($q) => $q->has('quotes')->orHas('invoices'))
        ->withMax('invoices', 'created_at')->withMax('quotes', 'created_at')
        ->get()
        ->map(function (\App\Models\Project $project) {
            $last = collect([$project->invoices_max_created_at, $project->quotes_max_created_at])->filter()->max();

            return [
                'client' => $project->client_id,
                'id' => $project->id,
                'worksite' => $project->worksite_id,
                'last' => (string) $last,
                'label' => $project->title.($last ? ' — dernier document le '.\Illuminate\Support\Carbon::parse($last)->format('d/m/Y') : ''),
            ];
        })
        ->sortByDesc('last')->groupBy('client')->map->values();
    $selected = (string) old('project', $invoice->project_id ?? '');
    $current = $projectsByClient[old('client_id', $invoice->client_id)] ?? collect();
@endphp
<div class="field span-2 @error('project') has-error @enderror">
    <label for="project">Chantier (frais et suivi)</label>
    <select id="project" name="project" data-project-select data-selected="{{ $selected }}">
        @foreach ($current as $project)
            <option value="{{ $project['id'] }}" @selected($selected === (string) $project['id'])>{{ $project['label'] }}</option>
        @endforeach
        <option value="new" @selected($selected === 'new' || ($selected === '' && $current->isEmpty()))>Nouveau chantier</option>
    </select>
    <span class="hint">Une nouvelle facture n'est pas forcément un nouveau chantier : elle peut rejoindre un chantier existant du client (ses frais et ses factures sont alors additionnés).</span>
</div>
<script type="application/json" id="invoice-projects-data">@json($projectsByClient)</script>
