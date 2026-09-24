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
    <div class="form-grid cols-2" style="margin-top:1rem">
        <x-field name="from" label="Expéditeur des emails du formulaire (contient…)" :value="$form['from']" placeholder="ex. wordpress@ ou jetpack" />
        <x-field name="subject" label="Objet des emails (contient…)" :value="$form['subject']" placeholder="ex. Demande de devis" />
    </div>
    <p class="small muted">Remplissez l'un ou l'autre (ou les deux). Aidez-vous du bouton « Voir mes derniers emails » ci-dessous.</p>
    <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>

    @if ($form['enabled'])
        <p class="small muted" style="margin-bottom:0">
            Dernière lecture : {{ $form['last_check_at'] ? \Illuminate\Support\Carbon::parse($form['last_check_at'])->format('d/m/Y à H:i') : 'pas encore' }}
            · {{ $imported }} demande(s) importée(s).
            @if ($form['last_error'])<br><span style="color:var(--danger, #c0392b)">Erreur : {{ $form['last_error'] }}</span>@endif
        </p>
    @endif
</form>

<form method="POST" action="{{ route('settings.site-form.preview') }}" class="card">
    @csrf
    <h2>Voir mes derniers emails</h2>
    <p class="muted small">Affiche l'expéditeur et l'objet de vos emails des 14 derniers jours, pour repérer ceux du formulaire, et montre ce que l'application en lirait. Rien n'est créé.</p>
    <button class="btn btn-secondary" type="submit">Voir mes derniers emails</button>

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
