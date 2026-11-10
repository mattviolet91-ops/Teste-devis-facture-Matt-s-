@extends('layouts.app', ['title' => 'Faire signer un document'])

{{-- Un document fourni par l'entreprise (PDF, photo ou scan), signé par le client sur place ou depuis son lien. --}}
@section('content')
    <div class="page-head">
        <div>
            <h1>Faire signer un document</h1>
            <p>Bon de commande, autorisation de travaux, procès-verbal de réception… Le client signe sur votre téléphone ou depuis le lien que vous lui envoyez.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert"><ul class="error-list">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('attachments.sign.store') }}" enctype="multipart/form-data" class="card form-grid" data-sign-send>
        @csrf
        <div class="field">
            <label for="client_id">Client *</label>
            <select id="client_id" name="client_id" required data-sign-client>
                <option value="">Choisir un client…</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected((int) old('client_id', $clientId) === $client->id)>{{ $client->displayName() }}@if ($client->city) — {{ $client->city }}@endif</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="target">Rattacher à</label>
            <select id="target" name="target" data-sign-target data-selected="{{ old('target') }}">
                <option value="">Seulement la fiche du client</option>
                @foreach ($data[old('client_id', $clientId)]['targets'] ?? [] as $target)
                    <option value="{{ $target['id'] }}" @selected(old('target') === $target['id'])>{{ $target['label'] }}</option>
                @endforeach
            </select>
            <span class="hint">Rattaché à un devis ou une facture, le document apparaît aussi sur la page que le client ouvre avec son lien.</span>
        </div>

        <fieldset class="choice-list">
            <legend>Le document *</legend>
            <div class="field">
                <label for="file">Un fichier PDF ou une photo</label>
                <input id="file" type="file" name="file" accept="application/pdf,image/jpeg,image/png,image/webp">
                <span class="hint">Un fichier Word ou Excel doit d'abord être enregistré en PDF.</span>
            </div>
            <div class="field" data-scan data-scan-optional>
                <label for="scan-pages-send">… ou scannez-le avec le téléphone</label>
                {{-- Champ envoyé : rempli page après page par le script (sans script : choix direct des photos). --}}
                <input id="scan-pages-send" type="file" name="pages[]" accept="image/*" multiple data-scan-pages>
                <input type="file" accept="image/*" capture="environment" hidden data-scan-camera>
                <div class="scan-thumbs" data-scan-thumbs></div>
                <div class="chips chips-wrap" data-scan-buttons hidden>
                    <button type="button" class="btn btn-secondary btn-sm" data-scan-take><x-icon name="camera" /> Photographier une page</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-scan-pick>Choisir dans les photos</button>
                </div>
                <span class="hint">Une photo par page : elles deviennent un seul PDF.</span>
            </div>
        </fieldset>
        <x-field name="name" label="Nom du document (facultatif)" maxlength="120" placeholder="ex. Autorisation de travaux" />

        <fieldset class="choice-list">
            <legend>Comment le client signe-t-il ? *</legend>
            @php $action = old('action', 'send'); @endphp
            <label class="check"><input type="radio" name="action" value="send" @checked($action === 'send') data-sign-action> <span><strong>En ligne, depuis son téléphone</strong><br><span class="small muted">Il reçoit un lien, lit le document et signe. Vous êtes prévenu.</span></span></label>
            <label class="check"><input type="radio" name="action" value="on_site" @checked($action === 'on_site') data-sign-action> <span><strong>Sur place, maintenant</strong><br><span class="small muted">Il signe du doigt sur votre téléphone.</span></span></label>
        </fieldset>

        <div data-action-only="send">
            <x-field name="to" label="Envoyer le lien par email à (facultatif)" type="email" maxlength="160" autocomplete="off" data-sign-email
                :hint="$canEmail ? 'Laissez vide pour envoyer le lien vous-même par WhatsApp ou SMS à l\'étape suivante.' : 'L\'envoi des emails n\'est pas configuré : vous pourrez envoyer le lien par WhatsApp ou SMS à l\'étape suivante.'" />
        </div>

        <div class="form-actions"><button class="btn" type="submit"><x-icon name="pen" /> Continuer</button>
            <a class="btn btn-secondary" href="{{ url()->previous() }}">Annuler</a></div>
    </form>

    <script type="application/json" id="sign-send-data">@json($data)</script>
    <script src="{{ asset('js/sign-send.js') }}?v={{ filemtime(public_path('js/sign-send.js')) }}" defer></script>
@endsection
