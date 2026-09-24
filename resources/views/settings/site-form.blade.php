@extends('settings.layout', ['title' => 'Formulaire du site'])

@section('settings')
<form method="POST" action="{{ route('settings.site-form') }}" class="card">
    @csrf
    @method('PUT')
    <h2>Formulaire de votre site internet</h2>
    <p class="muted small">Quand un visiteur remplit le formulaire de votre site, vous recevez un email. L'application lit ces emails dans votre boîte Gmail
        (toutes les 5 minutes, sans les modifier ni les marquer comme lus) et crée la demande de devis, avec une notification.
        Votre site n'est pas modifié.</p>

    @unless ($mailConfigured)
        <div class="alert alert-warning">Configurez d'abord Gmail dans <a href="{{ route('settings.emails') }}">Réglages → Emails</a>.</div>
    @endunless

    <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $form['enabled']))> <span><strong>Lire les demandes du formulaire du site</strong></span></label>
    <p class="small">Les emails du formulaire sont <strong>reconnus automatiquement</strong> (mentions ajoutées par WordPress). Vérifiez avec « Voir mes derniers emails » ci-dessous.</p>
    <details @if ($form['from'] || $form['subject']) open @endif>
        <summary class="small">Réglage avancé : reconnaître par l'objet (si la détection automatique ne suffit pas)</summary>
        <div class="form-grid cols-2" style="margin-top:.75rem">
            <x-field name="subject" label="Objet des emails du formulaire (contient…)" :value="$form['subject']" placeholder="ex. Demande de devis" hint="Le début de l'objet, identique pour toutes les demandes." />
            <x-field name="from" label="Expéditeur (contient…)" :value="$form['from']" placeholder="Laisser vide en général" hint="Pas l'adresse d'un client : l'expéditeur change souvent à chaque demande." />
        </div>
    </details>
    <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>

    @if ($form['enabled'])
        <p class="small muted" style="margin-bottom:0">
            Dernière lecture : {{ $form['last_check_at'] ? \Illuminate\Support\Carbon::parse($form['last_check_at'])->format('d/m/Y à H:i') : 'pas encore' }}
            · {{ $imported }} demande(s) importée(s).
            @if ($form['last_error'])<br><span style="color:var(--danger, #c0392b)">Erreur : {{ $form['last_error'] }}</span>@endif
        </p>
    @endif
</form>

<form method="POST" action="{{ route('settings.site-form.preview') }}" class="card" data-busy="Lecture de votre boîte Gmail… (jusqu'à 1 minute)">
    @csrf
    <h2>Voir mes derniers emails</h2>
    <p class="muted small">Affiche vos 30 derniers emails (7 derniers jours) : ceux reconnus comme venant du formulaire portent l'étiquette « formulaire », avec ce que l'application en lirait. Rien n'est créé.</p>
    <button class="btn btn-secondary" type="submit">Voir mes derniers emails</button>

    @if ($errors->has('preview'))
        <div class="alert alert-error" style="margin-top:1rem">{{ $errors->first('preview') }}</div>
    @endif
    @if (is_array($preview))
        <p class="small" style="margin:1rem 0 0">{{ count($preview) }} email(s) lu(s), dont <strong>{{ collect($preview)->where('matches', true)->count() }} reconnu(s) comme venant du formulaire</strong>.</p>
    @endif
    @if ($preview)
        <ul class="stat-list" style="margin-top:1rem">
            @foreach ($preview as $item)
                <li style="flex-direction:column;align-items:flex-start;gap:.125rem">
                    <span>@if ($item['matches'])<span class="badge badge-info">formulaire</span> @endif<strong>{{ $item['subject'] ?: '(sans objet)' }}</strong></span>
                    <span class="small muted">{{ $item['from'] }} · {{ $item['date'] }}</span>
                    @if ($item['parsed'])
                        <span class="small">→ {{ collect($item['parsed'])->filter()->implode(' · ') ?: 'aucun champ reconnu' }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</form>
@endsection
