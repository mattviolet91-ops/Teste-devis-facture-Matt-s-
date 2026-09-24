@extends('settings.layout', ['title' => 'Emails'])

@section('settings')
    @error('mail_test')<div class="alert alert-error" role="alert">{{ $message }}</div>@enderror

    <form method="POST" action="{{ route('settings.emails') }}">
        @csrf
        @method('PUT')
        <div class="card">
            <div class="card-head">
                <h2>Envoi par Gmail</h2>
                <span class="badge {{ $configured ? 'badge-success' : 'badge-warning' }}">{{ $configured ? 'Configuré' : 'Non configuré' }}</span>
            </div>
            <p class="muted small">Les emails partent de votre adresse Gmail, avec le PDF en pièce jointe. Il faut un <strong>mot de passe d'application</strong> Google
                (compte Google → Sécurité → Validation en deux étapes → Mots de passe des applications). Ce n'est pas votre mot de passe Gmail habituel.
                Il est enregistré chiffré et n'est jamais réaffiché.</p>
            <div class="form-grid cols-2">
                <x-field name="username" label="Adresse Gmail" type="email" :value="$username" required />
                <x-field name="from_name" label="Nom de l'expéditeur" :value="$mail['from_name']" :placeholder="$settings->get('company.trade_name')" />
                <div class="field span-2 @error('password') has-error @enderror">
                    <label for="password">Mot de passe d'application</label>
                    <input id="password" type="password" name="password" autocomplete="new-password" placeholder="{{ $hasPassword ? '•••• enregistré — laisser vide pour le garder' : 'xxxx xxxx xxxx xxxx' }}">
                    @error('password')<span class="error">{{ $message }}</span>@enderror
                </div>
                @if ($hasPassword)
                    <label class="check span-2"><input type="checkbox" name="forget_password" value="1"> <span>Supprimer le mot de passe enregistré</span></label>
                @endif
                <label class="check span-2"><input type="checkbox" name="bcc_self" value="1" @checked(old('bcc_self', $mail['bcc_self']))>
                    <span>Recevoir une copie cachée de chaque email ({{ $settings->get('company.email') }})</span></label>
            </div>
            <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
        </div>
    </form>

    @if ($configured)
        <form method="POST" action="{{ route('settings.emails.test') }}" class="card">
            @csrf
            <p style="margin:0 0 .75rem">Vérifiez la connexion en vous envoyant un email de test à {{ $settings->get('company.email') }}.</p>
            <button class="btn btn-secondary" type="submit"><x-icon name="send" /> Envoyer un email de test</button>
        </form>
    @endif

    <form method="POST" action="{{ route('settings.emails.share') }}" class="card" id="sms">
        @csrf
        @method('PUT')
        <h2>Messages SMS / WhatsApp</h2>
        <p class="muted small">Texte proposé par les boutons WhatsApp, SMS et « Copier le message » d'un devis ou d'une facture. Mêmes variables que les emails ({lien}, {numero}, {montant}…).</p>
        <div class="form-grid">
            <x-field name="sms_quote" label="Pour un devis" type="textarea" rows="6" :value="$mail['sms_quote']" required />
            <x-field name="sms_invoice" label="Pour une facture" type="textarea" rows="6" :value="$mail['sms_invoice']" required />
            <x-field name="reminder_before" label="Relance : rappel avant l'échéance" type="textarea" rows="6" :value="$mail['reminder_before']" required />
            <x-field name="reminder_after" label="Relance : facture en retard ({retard} = « en retard de 5 jours »)" type="textarea" rows="7" :value="$mail['reminder_after']" required />
        </div>
        <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
    </form>

    <form method="POST" action="{{ route('settings.emails.reminders') }}" class="card">
        @csrf
        @method('PUT')
        <h2>Relances des factures</h2>
        <p class="muted small">Chaque matin, les factures en retard reçoivent le modèle « Relance de paiement » avec le PDF, si le client a une adresse email.
            Vous êtes prévenu sur votre téléphone. Vous pouvez toujours relancer à la main avec le bouton « Relancer » d'une facture.</p>
        <label class="check"><input type="checkbox" name="notify_enabled" value="1" @checked($reminders['notify_enabled'])>
            <span>Me prévenir chaque matin (notification) des factures à relancer : 3 jours avant l'échéance, le jour même, puis après 1, 7, 15 et 30 jours de retard</span></label>
        <label class="check" style="margin-top:.5rem"><input type="checkbox" name="auto_enabled" value="1" @checked($reminders['auto_enabled'])> <span>Envoyer aussi des relances automatiques par email aux clients</span></label>
        <div class="form-grid cols-2" style="margin-top:.75rem">
            <x-field name="first_after_days" label="Première relance (jours après l'échéance)" type="number" min="0" max="90" :value="$reminders['first_after_days']" required />
            <x-field name="repeat_days" label="Puis tous les (jours)" type="number" min="1" max="90" :value="$reminders['repeat_days']" required />
            <x-field name="max" label="Nombre maximum de relances" type="number" min="1" max="10" :value="$reminders['max']" required />
        </div>
        <div class="form-actions"><button class="btn" type="submit">Enregistrer</button></div>
    </form>

    <div class="card" id="modeles">
        <h2>Modèles d'emails</h2>
        <p class="muted small">Les mots entre accolades sont remplacés automatiquement à l'envoi :</p>
        <ul class="variables">
            @foreach ($variables as $key => $help)<li><code>{{ '{'.$key.'}' }}</code> <span class="muted small">{{ $help }}</span></li>@endforeach
        </ul>
    </div>

    @foreach ($templates as $template)
        <details class="card template-card">
            <summary>
                <strong>{{ $template->name }}</strong>
                <span class="muted small">{{ (\App\Models\EmailTemplate::CONTEXTS[$template->context] ?? '').($template->is_default ? ' · par défaut' : '') }}</span>
            </summary>
            <form method="POST" action="{{ route('settings.emails.templates.update', $template) }}">
                @csrf
                @method('PUT')
                @include('settings._email-template-fields', ['template' => $template, 'prefix' => 't'.$template->id])
                <div class="form-actions">
                    <button class="btn" type="submit">Enregistrer</button>
                </div>
            </form>
            <form method="POST" action="{{ route('settings.emails.templates.destroy', $template) }}" data-confirm="Supprimer le modèle « {{ $template->name }} » ?">
                @csrf
                @method('DELETE')
                <button class="btn btn-danger-outline btn-sm" type="submit">Supprimer ce modèle</button>
            </form>
        </details>
    @endforeach

    <details class="card template-card" @if ($errors->any() && old('_new')) open @endif>
        <summary><strong>+ Nouveau modèle</strong></summary>
        <form method="POST" action="{{ route('settings.emails.templates.store') }}">
            @csrf
            <input type="hidden" name="_new" value="1">
            @include('settings._email-template-fields', ['template' => new \App\Models\EmailTemplate(['context' => 'quote']), 'prefix' => 'new'])
            <div class="form-actions"><button class="btn" type="submit">Ajouter</button></div>
        </form>
    </details>
@endsection
