@extends('layouts.app', ['title' => 'Nouveau frais'])

@php $assujetti = app(\App\Services\Settings::class)->get('vat.regime') === 'assujetti'; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Nouveau frais</h1>
            <p>Notez une dépense dès que vous la faites : matériaux, location, déchetterie… Visible par vous seul.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('expenses.store.any') }}" enctype="multipart/form-data" class="card form-grid cols-2">
        @csrf
        <div class="field span-2 @error('job') has-error @enderror">
            <label for="job">Chantier *</label>
            <select id="job" name="job" required>
                <option value="">Choisir…</option>
                <option value="general" @selected(old('job', $selected) === 'general')>Frais généraux (sans chantier)</option>
                @foreach ($jobs as $job)
                    <option value="{{ $job['key'] }}" @selected(old('job', $selected) === $job['key'])>{{ $job['client']?->displayName() }} · {{ $job['title'] }}{{ $job['billed'] ? '' : ' (pas encore facturé)' }}</option>
                @endforeach
            </select>
            @error('job')<span class="error">{{ $message }}</span>@enderror
            <span class="hint">Un chantier apparaît dès que son devis est accepté.</span>
        </div>
        @include('expenses._fields', ['assujetti' => $assujetti])
        <div class="span-2"><button class="btn" type="submit"><x-icon name="plus" /> Enregistrer le frais</button></div>
    </form>
@endsection
