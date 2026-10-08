@extends('layouts.app', ['title' => 'Réglages · Argent'])

@section('content')
    @include('money._nav')

    <form method="POST" action="{{ route('money.settings.update') }}" class="card">
        @csrf
        @method('PUT')
        <h2>Lien avec le logiciel de devis</h2>
        <p class="small muted">Chaque lundi matin, les paiements reçus et les frais des chantiers sont copiés ici (sans doublon), puis le bilan de la semaine est fait.
            @if ($lastSync) Dernière mise à jour : {{ $lastSync->format('d/m/Y à H:i') }}{{ isset($lastResult['added']) ? ' ('.$lastResult['added'].' ajouté(s), '.$lastResult['updated'].' modifié(s), '.$lastResult['removed'].' retiré(s))' : '' }}.@endif
        </p>
        <div class="form-grid cols-2">
            <div class="field">
                <label for="sync_account_id">Compte qui reçoit les paiements et les frais</label>
                <select id="sync_account_id" name="sync_account_id">
                    <option value="">— Lien désactivé —</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected($syncAccount?->id === $account->id)>{{ $account->name }} ({{ $account->scopeLabel() }})</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="cash_account_id">Paiements en espèces sur <span class="muted small">(facultatif)</span></label>
                <select id="cash_account_id" name="cash_account_id">
                    <option value="">Le même compte</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected($cashAccount?->id === $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
            <x-field name="tax_rate" label="À mettre de côté pour l'URSSAF et les impôts (%)" :value="$taxRate ? \App\Support\Percent::input($taxRate) : ''" inputmode="decimal" placeholder="ex. 21,2" hint="Appliqué à ce qui est encaissé sur les comptes pro. Vide : ne pas afficher." />
        </div>

        <h2 style="margin-top:1.5rem">Sécurité</h2>
        <div class="form-grid cols-2">
            <div class="field">
                <label for="lock_minutes">Verrouiller tout seul après</label>
                <select id="lock_minutes" name="lock_minutes">
                    @foreach (\App\Services\MoneyLockService::DELAYS as $minutes => $label)
                        <option value="{{ $minutes }}" @selected($lockMinutes === $minutes)>{{ $label }} sans activité</option>
                    @endforeach
                </select>
            </div>
        </div>

        <h2 style="margin-top:1.5rem">Notification du lundi</h2>
        <label class="check"><input type="checkbox" name="weekly_push" value="1" @checked($weeklyPush)> <span>Me prévenir quand le bilan de la semaine est prêt</span></label>
        <label class="check" style="margin-top:.5rem"><input type="checkbox" name="push_amounts" value="1" @checked($pushAmounts)> <span>Montrer les montants dans la notification <span class="muted small">(visibles sur l'écran verrouillé du téléphone)</span></span></label>

        <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
    </form>

    <div class="card">
        <h2>Mettre à jour maintenant</h2>
        <p class="small muted">Sans attendre lundi : paiements et frais des devis, et dépenses fixes arrivées à échéance.</p>
        <form method="POST" action="{{ route('money.sync') }}" data-busy="Mise à jour…">
            @csrf
            <button class="btn btn-secondary" type="submit"><x-icon name="repeat" /> Mettre à jour</button>
        </form>
    </div>

    <form method="POST" action="{{ route('money.settings.code') }}" class="card">
        @csrf
        @method('PUT')
        <h2>Changer le code Argent</h2>
        <div class="form-grid cols-2">
            <div class="field @error('current_code') has-error @enderror">
                <label for="current_code">Code actuel</label>
                <input id="current_code" class="pin-input" type="password" name="current_code" inputmode="numeric" maxlength="8" autocomplete="off" required>
                @error('current_code')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div></div>
            <div class="field @error('code') has-error @enderror">
                <label for="code">Nouveau code (4 à 8 chiffres)</label>
                <input id="code" class="pin-input" type="password" name="code" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" required>
                @error('code')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="code_confirmation">Le même, une 2e fois</label>
                <input id="code_confirmation" class="pin-input" type="password" name="code_confirmation" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" required>
            </div>
        </div>
        <div class="form-actions"><button class="btn" type="submit"><x-icon name="lock" /> Changer le code</button></div>
    </form>

    <div class="card">
        <h2>Vos données</h2>
        <p class="small muted">Tout est gardé sur votre serveur et inclus dans la sauvegarde quotidienne de l'application. Vous pouvez aussi télécharger tous les mouvements (fichier CSV pour Excel).</p>
        <div class="money-actions">
            <a class="btn btn-secondary" href="{{ route('money.export') }}"><x-icon name="file" /> Télécharger mes mouvements</a>
            <a class="btn btn-secondary" href="{{ route('money.import.create') }}"><x-icon name="upload" /> Importer un relevé</a>
        </div>
    </div>
@endsection
