@extends('settings.layout', ['title' => 'Comptes'])

@section('settings')
@if ($temporaryPassword)
    <div class="alert alert-warning" role="alert">
        <strong>Mot de passe provisoire de {{ $temporaryPassword['name'] }}</strong> (affiché une seule fois) :<br>
        Adresse : {{ $temporaryPassword['email'] }}<br>
        Mot de passe : <code style="font-size:1.1rem">{{ $temporaryPassword['password'] }}</code><br>
        <span class="small">Transmettez-le de vive voix ou par SMS ; il pourra le changer dans Réglages → Mon compte.</span>
    </div>
@endif

<div class="card">
    <h2>Comptes d'accès</h2>
    <p class="muted small">Un compte <strong>commercial</strong> voit les prospects, clients, demandes de devis, rendez-vous et devis (il peut en créer et faire signer).
        Il ne voit jamais les factures, paiements, chiffre d'affaires, statistiques ni réglages de l'entreprise.</p>
    <ul class="stat-list">
        @foreach ($users as $account)
            <li style="flex-wrap:wrap;gap:.5rem">
                <span>
                    <strong>{{ $account->name }}</strong>
                    @if ($account->is(auth()->user()))<span class="badge">vous</span>@endif
                    <span class="badge {{ $account->isAdmin() ? 'badge-info' : '' }}">{{ $account->isAdmin() ? 'Gérant' : 'Commercial' }}</span>
                    @if ($account->disabled_at)<span class="badge badge-danger">désactivé</span>@endif
                    <br><span class="small muted">{{ $account->email }} · {{ $account->last_login_at ? 'dernière connexion le '.$account->last_login_at->format('d/m/Y à H:i') : 'jamais connecté' }}</span>
                </span>
                @unless ($account->is(auth()->user()))
                    <span class="action-bar" style="margin:0">
                        @unless ($account->last_login_at || $account->disabled_at)
                            <form method="POST" action="{{ route('settings.users.resend', $account) }}">@csrf<button class="btn btn-secondary btn-sm" type="submit">Renvoyer l'invitation</button></form>
                        @endunless
                        <form method="POST" action="{{ route('settings.users.toggle', $account) }}" data-confirm="{{ $account->disabled_at ? 'Réactiver ce compte ?' : 'Désactiver ce compte ? Il sera déconnecté de tous ses appareils.' }}">
                            @csrf
                            <button class="btn btn-secondary btn-sm" type="submit">{{ $account->disabled_at ? 'Réactiver' : 'Désactiver' }}</button>
                        </form>
                        @unless ($account->isAdmin())
                            <form method="POST" action="{{ route('settings.users.destroy', $account) }}" data-confirm="Supprimer définitivement le compte de {{ $account->name }} ? Ses devis et rendez-vous sont conservés.">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-danger-outline btn-sm" type="submit">Supprimer</button>
                            </form>
                        @endunless
                    </span>
                @endunless
            </li>
        @endforeach
    </ul>
</div>

<form method="POST" action="{{ route('settings.users.store') }}" class="card">
    @csrf
    <h2>Créer un compte</h2>
    @error('email')<div class="alert alert-error">{{ $message }}</div>@enderror
    <div class="form-grid cols-2">
        <x-field name="name" label="Nom et prénom" required />
        <x-field name="email" label="Adresse email" type="email" required hint="Il reçoit un email pour choisir son mot de passe." />
        <x-select name="role" label="Type de compte" :options="\App\Models\User::ROLES" value="commercial" :placeholder="false" class="span-2" />
    </div>
    <div class="form-actions"><button class="btn" type="submit"><x-icon name="plus" /> Créer le compte</button></div>
</form>
@endsection
