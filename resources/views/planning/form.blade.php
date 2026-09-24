@php
    $appointment = old('kind', $intervention->kind) === 'rdv';
    $noun = $appointment ? 'le rendez-vous' : 'l\'intervention';
    $heading = $intervention->exists ? 'Modifier '.$noun : 'Ajouter au planning';
@endphp
@extends('layouts.app', ['title' => $heading])

@section('content')
    <div class="page-head">
        <h1>{{ $heading }}</h1>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert"><ul class="error-list">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ $intervention->exists ? route('planning.update', $intervention) : route('planning.store') }}" data-offline="Planning" data-planning-form>
        @csrf
        @if ($intervention->exists) @method('PUT') @endif
        <div class="card">
            <div class="chips" role="radiogroup" aria-label="Type" style="margin-bottom:1rem">
                @foreach (\App\Models\Intervention::KINDS as $key => $label)
                    <label class="chip chip-radio"><input type="radio" name="kind" value="{{ $key }}" @checked(old('kind', $intervention->kind) === $key) data-planning-kind>
                        <x-icon :name="$key === 'rdv' ? 'users' : 'tool'" /> {{ $label }}</label>
                @endforeach
            </div>

            <div class="form-grid cols-2">
                <div class="field span-2 @error('client_id') has-error @enderror">
                    <label for="client_id">Client <span data-kind-only="chantier">*</span><span data-kind-only="rdv" class="muted small">(facultatif)</span></label>
                    <div class="client-search">
                        <input type="search" placeholder="Rechercher : nom, téléphone, ville…" aria-label="Rechercher un client" autocomplete="off" data-client-search>
                        <div class="client-search-results" data-client-results hidden></div>
                    </div>
                    <select id="client_id" name="client_id" data-planning-client>
                        <option value="">{{ $appointment ? 'Aucun client (fournisseur, comptable…)' : 'Choisir un client…' }}</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) old('client_id', $intervention->client_id) === (string) $client->id)
                                data-search="{{ \App\Support\Search::index([$client->displayName(), $client->company_name, $client->first_name, $client->last_name, $client->email, $client->city, preg_replace('/\D/', '', (string) $client->phone)]) }}">{{ $client->displayName() }}{{ $client->city ? ' — '.$client->city : '' }}</option>
                        @endforeach
                    </select>
                    @error('client_id')<span class="error">{{ $message }}</span>@enderror
                    <span class="hint">Nouveau prospect ? <a href="{{ route('clients.create') }}">Créez d'abord sa fiche</a>.</span>
                </div>
                <div class="field @error('worksite_id') has-error @enderror">
                    <label for="worksite_id">Chantier</label>
                    <select id="worksite_id" name="worksite_id" data-planning-worksite data-selected="{{ old('worksite_id', $intervention->worksite_id) }}"><option value="">Adresse du client</option></select>
                    @error('worksite_id')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field @error('quote_id') has-error @enderror">
                    <label for="quote_id">Devis</label>
                    <select id="quote_id" name="quote_id" data-planning-quote>
                        <option value="">—</option>
                        @foreach ($quotes as $quote)
                            <option value="{{ $quote->id }}" data-client="{{ $quote->client_id }}" data-title="{{ $quote->title }}" @selected((string) old('quote_id', $intervention->quote_id) === (string) $quote->id)>{{ $quote->number }} · {{ $quote->client?->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('quote_id')<span class="error">{{ $message }}</span>@enderror
                </div>
                <x-field name="location" label="Lieu (si autre que le chantier)" :value="$intervention->location" class="span-2" placeholder="ex. Point P Massy, ou adresse d'un prospect" />

                <div class="field span-2 @error('title') has-error @enderror">
                    <label for="title">Objet *</label>
                    <input id="title" type="text" name="title" value="{{ old('title', $intervention->title) }}" required maxlength="200" list="appointment-titles" placeholder="ex. Démoussage et traitement hydrofuge">
                    <datalist id="appointment-titles">
                        @foreach (\App\Models\Intervention::APPOINTMENT_TITLES as $title)<option value="{{ $title }}">@endforeach
                    </datalist>
                    @error('title')<span class="error">{{ $message }}</span>@enderror
                </div>

                <x-field name="starts_on" label="Date" type="date" :value="$intervention->starts_on?->toDateString()" required />
                <x-field name="start_time" label="Heure" type="time" :value="$intervention->start_time" />
                <div data-kind-only="rdv">
                    <x-field name="end_time" label="Heure de fin" type="time" :value="$intervention->end_time" hint="Vide = 1 heure." />
                </div>
                <div data-kind-only="chantier">
                    <x-field name="ends_on" label="Fin (si plusieurs jours)" type="date" :value="$intervention->exists && $intervention->days() > 1 ? $intervention->ends_on->toDateString() : ''" />
                </div>
                @if ($intervention->exists)
                    <x-select name="status" label="Statut" :options="\App\Models\Intervention::STATUSES" :value="$intervention->status" :placeholder="false" />
                @endif
                <x-field name="notes" label="Notes (matériel, accès, code portail…)" type="textarea" rows="3" :value="$intervention->notes" class="span-2" />
            </div>
        </div>
        <div class="form-actions sticky-actions">
            <button class="btn" type="submit">Enregistrer</button>
            <a class="btn btn-secondary" href="{{ $intervention->exists ? route('planning.show', $intervention) : route('planning.index') }}">Annuler</a>
        </div>
    </form>

    <script type="application/json" id="planning-worksites">@json($worksites)</script>
    <script src="{{ asset('js/planning.js') }}?v={{ filemtime(public_path('js/planning.js')) }}" defer></script>
@endsection
