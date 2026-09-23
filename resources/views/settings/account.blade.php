@extends('settings.layout', ['title' => 'Mon compte'])

@section('settings')
<div class="card" data-push data-push-key="{{ $pushKey }}" data-subscribe="{{ route('push.subscribe') }}"
    data-unsubscribe="{{ route('push.unsubscribe') }}" data-test="{{ route('push.test') }}">
    @csrf
    <h2>Notifications sur le téléphone</h2>
    <p class="muted small">Recevez une notification quand un client ouvre, accepte, refuse ou demande à modifier un devis, et avant l'échéance de votre assurance.
        Sur iPhone, ouvrez d'abord l'application depuis l'icône de l'écran d'accueil.</p>
    <p data-push-status role="status">Vérification…</p>
    <div class="action-bar">
        <button class="btn" type="button" data-push-enable hidden>Activer les notifications</button>
        <button class="btn btn-secondary" type="button" data-push-test hidden>Envoyer une notification de test</button>
        <button class="btn btn-secondary" type="button" data-push-disable hidden>Désactiver sur cet appareil</button>
    </div>
    @if ($devices->isNotEmpty())
        <p class="small muted" style="margin-bottom:0">Appareils abonnés : {{ $devices->count() }}</p>
    @endif
</div>
<script src="{{ asset('js/push.js') }}?v={{ filemtime(public_path('js/push.js')) }}" defer></script>

<form method="POST" action="{{ route('settings.account.profile') }}" class="card">
    @csrf
    @method('PUT')
    <h2>Profil</h2>
    <div class="form-grid cols-2">
        <x-field name="name" label="Nom" :value="$user->name" required />
        <x-field name="email" label="Email de connexion" type="email" :value="$user->email" autocomplete="username" required />
    </div>
    <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
</form>

<form method="POST" action="{{ route('settings.account.password') }}" class="card">
    @csrf
    @method('PUT')
    <h2>Mot de passe</h2>
    <div class="form-grid cols-2">
        <x-field name="current_password" label="Mot de passe actuel" type="password" autocomplete="current-password" class="span-2" required />
        <x-field name="password" label="Nouveau mot de passe" type="password" autocomplete="new-password" hint="12 caractères minimum, avec des lettres et des chiffres." required />
        <x-field name="password_confirmation" label="Confirmation" type="password" autocomplete="new-password" required />
    </div>
    <div class="form-actions"><button class="btn" type="submit">Changer le mot de passe</button></div>
</form>

<div class="card">
    <h2>Dernière connexion</h2>
    <p class="muted" style="margin:0">
        @if ($user->last_login_at)
            {{ $user->last_login_at->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm') }} — adresse IP {{ $user->last_login_ip }}
        @else
            —
        @endif
    </p>
</div>
@endsection
