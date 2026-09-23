@extends('settings.layout', ['title' => 'Assurance'])

@section('settings')
    @if ($message)
        <div class="alert {{ $level === 'danger' ? 'alert-error' : 'alert-warning' }}" role="alert">{{ $message }}</div>
    @endif

    <form method="POST" action="{{ route('settings.insurance') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="card">
            <div class="card-head">
                <h2>Assurance décennale</h2>
                @if ($daysLeft !== null)
                    <span class="badge {{ ['ok' => 'badge-success', 'warning' => 'badge-warning', 'danger' => 'badge-danger'][$level] }}">
                        {{ $daysLeft < 0 ? 'Expirée' : ($daysLeft === 0 ? 'Expire aujourd\'hui' : 'Encore '.$daysLeft.' jour'.($daysLeft > 1 ? 's' : '')) }}
                    </span>
                @endif
            </div>
            <p class="muted small">Mention obligatoire imprimée sur les devis et les factures. Vous êtes prévenu 15 jours puis 5 jours avant l'échéance (sur l'accueil et par email).</p>
            <div class="form-grid cols-2">
                <x-field name="insurance.insurer" label="Assureur" :value="$insurance['insurer']" required />
                <x-field name="insurance.broker" label="Courtier" :value="$insurance['broker']" />
                <x-field name="insurance.insurer_address" label="Adresse de l'assureur" :value="$insurance['insurer_address']" class="span-2" />
                <x-field name="insurance.policy_number" label="N° de contrat" :value="$insurance['policy_number']" required />
                <x-field name="insurance.coverage_area" label="Couverture géographique" :value="$insurance['coverage_area']" required />
                <x-field name="insurance.valid_from" label="Valable du" type="date" :value="$insurance['valid_from']" required />
                <x-field name="insurance.valid_until" label="Au" type="date" :value="$insurance['valid_until']" required />
                <x-field name="insurance.activities" label="Activités couvertes" :value="$insurance['activities']" class="span-2" required />
                <div class="field span-2 @error('certificate') has-error @enderror">
                    <label for="certificate">Attestation (PDF ou photo)</label>
                    <input id="certificate" type="file" name="certificate" accept="application/pdf,image/jpeg,image/png">
                    <span class="hint">Pour une nouvelle année : changez les dates et ajoutez la nouvelle attestation.</span>
                    @error('certificate')<span class="error">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
        </div>
    </form>

    <div class="card">
        <h2>Historique des attestations</h2>
        @if ($certificates->isEmpty())
            <p class="muted" style="margin:0">Aucune attestation enregistrée. Ajoutez le PDF de votre attestation ci-dessus : vous pourrez la joindre à vos emails.</p>
        @else
            <ul class="stat-list">
                @foreach ($certificates as $certificate)
                    <li>
                        <span>
                            <strong>{{ $certificate->insurer }}</strong> — n° {{ $certificate->policy_number }}<br>
                            <span class="muted small">du {{ $certificate->valid_from->format('d/m/Y') }} au {{ $certificate->valid_until->format('d/m/Y') }} · ajoutée le {{ $certificate->created_at->format('d/m/Y') }}</span>
                        </span>
                        @if ($certificate->path)
                            <a class="btn btn-secondary btn-sm" href="{{ route('settings.insurance.certificate', $certificate) }}" target="_blank" rel="noopener">Voir</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
