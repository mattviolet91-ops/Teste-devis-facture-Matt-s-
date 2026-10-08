@extends('settings.layout', ['title' => 'Accès Claude'])

@section('settings')
    <div class="card">
        <h2>Créer des devis en parlant à Claude</h2>
        <p>Avec une clé d'accès, Claude peut créer des <strong>devis brouillons</strong> dans l'application quand vous lui dites « devis pour Mme Martin : démoussage 120 m² à 12 €… ».</p>
        <ul class="small">
            <li>Claude peut seulement : chercher un client, lire vos prestations, créer un brouillon.</li>
            <li>Il ne peut <strong>jamais</strong> envoyer un devis, voir vos factures ou vos paiements, ni rien supprimer.</li>
            <li>Chaque devis créé ainsi est noté dans le journal. Vous le vérifiez et l'envoyez vous-même.</li>
        </ul>
    </div>

    @if ($newToken)
        <div class="card" style="border-color: var(--success)">
            <h2>Votre nouvelle clé</h2>
            @if ($newTokenScope === 'argent')
                <p class="small">Copiez-la maintenant et collez-la dans l'app Argent : Réglages → Lien avec l'app de devis, avec l'adresse <strong>{{ rtrim((string) config('app.url'), '/') }}</strong>. <strong>Elle ne sera plus jamais affichée.</strong> Ne l'envoyez jamais dans une conversation, un email ou un SMS.</p>
            @else
                <p class="small">Copiez-la maintenant et collez-la dans les réglages de votre environnement Claude (voir ci-dessous). <strong>Elle ne sera plus jamais affichée.</strong> Ne l'envoyez jamais dans une conversation, un email ou un SMS.</p>
            @endif
            <div class="copy-field">
                <input type="text" value="{{ $newToken }}" readonly aria-label="Clé d'accès" data-copy-source>
                <button class="btn btn-secondary btn-sm" type="button" data-copy>Copier</button>
            </div>
        </div>
    @endif

    <div class="card">
        <h2>Clés d'accès</h2>
        @if ($tokens->isEmpty())
            <p class="muted">Aucune clé.</p>
        @else
            <ul class="stat-list">
                @foreach ($tokens as $token)
                    <li>
                        <span>{{ $token->name }} <span class="badge">{{ ($token->scope ?? 'claude') === 'argent' ? 'App Argent' : 'Claude' }}</span> <span class="muted small">· créée le {{ $token->created_at->format('d/m/Y') }} · {{ $token->last_used_at ? 'utilisée le '.$token->last_used_at->format('d/m/Y à H:i') : 'jamais utilisée' }}</span></span>
                        <form method="POST" action="{{ route('settings.api.destroy', $token) }}" data-confirm="Révoquer la clé « {{ $token->name }} » ? {{ ($token->scope ?? 'claude') === 'argent' ? 'L\'app Argent ne recevra plus les paiements et frais.' : 'Claude ne pourra plus créer de devis avec.' }}">
                            @csrf
                            @method('DELETE')
                            <button class="link-danger" type="submit">Révoquer</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
        <form method="POST" action="{{ route('settings.api.store') }}" class="form-grid cols-2" style="margin-top:1rem">
            @csrf
            <x-field name="name" label="Nom de la clé" value="Claude" required />
            <div class="field" style="align-self:end"><button class="btn" type="submit">Créer une clé</button></div>
        </form>
    </div>

    <div class="card">
        <h2>App Argent</h2>
        <p class="small">Votre application Argent (comptes perso et pro) lit chaque semaine les <strong>paiements reçus</strong>, les <strong>frais</strong> et ce qui <strong>reste à encaisser</strong>. Sa clé ne permet que cette lecture : ni modifier, ni créer, ni voir les clients en détail.</p>
        <form method="POST" action="{{ route('settings.api.store') }}">
            @csrf
            <input type="hidden" name="scope" value="argent">
            <input type="hidden" name="name" value="App Argent">
            <button class="btn" type="submit">Créer la clé de l'app Argent</button>
        </form>
    </div>

    <div class="card">
        <h2>Où mettre la clé</h2>
        <p class="small">Dans les réglages de l'environnement de votre session Claude Code (variables d'environnement / secrets), ajoutez :</p>
        <div class="copy-field">
            <input type="text" value="MC_API_URL={{ $apiUrl }}" readonly aria-label="Adresse de l'API" data-copy-source>
            <button class="btn btn-secondary btn-sm" type="button" data-copy>Copier</button>
        </div>
        <p class="small" style="margin:.5rem 0 0"><code>MC_API_TOKEN=</code> suivi de la clé copiée ci-dessus.</p>
    </div>
@endsection
