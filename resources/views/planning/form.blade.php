@extends('layouts.app', ['title' => $intervention->exists ? 'Modifier l\'intervention' : 'Planifier une intervention'])

@section('content')
    <div class="page-head">
        <h1>{{ $intervention->exists ? 'Modifier l\'intervention' : 'Planifier une intervention' }}</h1>
    </div>

    <form method="POST" action="{{ $intervention->exists ? route('planning.update', $intervention) : route('planning.store') }}">
        @csrf
        @if ($intervention->exists) @method('PUT') @endif
        <div class="card">
            <div class="form-grid cols-2">
                <div class="field span-2 @error('client_id') has-error @enderror">
                    <label for="client_id">Client *</label>
                    <select id="client_id" name="client_id" required data-planning-client>
                        <option value="">Choisir un client…</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) old('client_id', $intervention->client_id) === (string) $client->id)>{{ $client->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('client_id')<span class="error">{{ $message }}</span>@enderror
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
                <x-field name="title" label="Intitulé *" :value="$intervention->title" class="span-2" placeholder="ex. Démoussage et traitement hydrofuge" required />
                <x-field name="starts_on" label="Début *" type="date" :value="$intervention->starts_on?->toDateString()" required />
                <x-field name="start_time" label="Heure d'arrivée" type="time" :value="$intervention->start_time" />
                <x-field name="ends_on" label="Fin (si plusieurs jours)" type="date" :value="$intervention->ends_on?->toDateString()" />
                @if ($intervention->exists)
                    <x-select name="status" label="Statut" :options="\App\Models\Intervention::STATUSES" :value="$intervention->status" :placeholder="false" />
                @endif
                <x-field name="notes" label="Notes (matériel, accès, benne…)" type="textarea" rows="3" :value="$intervention->notes" class="span-2" />
            </div>
        </div>
        <div class="form-actions sticky-actions">
            <button class="btn" type="submit">Enregistrer</button>
            <a class="btn btn-secondary" href="{{ $intervention->exists ? route('planning.show', $intervention) : route('planning.index') }}">Annuler</a>
        </div>
    </form>

    <script type="application/json" id="planning-worksites">@json($worksites)</script>
    <script>
        (function () {
            var client = document.querySelector('[data-planning-client]');
            var site = document.querySelector('[data-planning-worksite]');
            var quote = document.querySelector('[data-planning-quote]');
            var title = document.getElementById('title');
            var data = JSON.parse(document.getElementById('planning-worksites').textContent || '{}');
            function fill() {
                var keep = site.getAttribute('data-selected');
                site.length = 1;
                (data[client.value] || []).forEach(function (w, i) {
                    var o = new Option(w.label, w.id);
                    if (String(w.id) === keep || (!keep && i === 0)) { o.selected = true; }
                    site.add(o);
                });
                Array.prototype.forEach.call(quote.options, function (o) { o.hidden = o.value && client.value && o.getAttribute('data-client') !== client.value; });
            }
            client.addEventListener('change', function () { site.setAttribute('data-selected', ''); if (quote.selectedOptions[0] && quote.selectedOptions[0].hidden) { quote.value = ''; } fill(); });
            quote.addEventListener('change', function () {
                var o = quote.selectedOptions[0];
                if (!o || !o.value) { return; }
                if (client.value !== o.getAttribute('data-client')) { client.value = o.getAttribute('data-client'); site.setAttribute('data-selected', ''); fill(); }
                if (!title.value && o.getAttribute('data-title')) { title.value = o.getAttribute('data-title'); }
            });
            fill();
        })();
    </script>
@endsection
