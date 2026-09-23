@extends('layouts.app', ['title' => 'Envoyer un email'])

@php
    $documentLabel = match (true) {
        $document instanceof \App\Models\Quote => 'Devis '.$document->displayNumber(),
        $document instanceof \App\Models\Invoice => $document->kindLabel().' '.$document->displayNumber(),
        default => null,
    };
    $backUrl = match (true) {
        $document instanceof \App\Models\Quote => route('quotes.show', $document),
        $document instanceof \App\Models\Invoice => route('invoices.show', $document),
        default => route('clients.show', $client),
    };
    $initial = $selected ? $rendered[$selected->id] : ['subject' => '', 'body' => ''];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Envoyer un email</h1>
            <p><a href="{{ route('clients.show', $client) }}">{{ $client->displayName() }}</a>@if ($documentLabel) · {{ $documentLabel }}@endif</p>
        </div>
    </div>

    @include('documents._errors')

    @unless ($configured)
        <div class="alert alert-warning">
            L'envoi direct n'est pas encore configuré. Renseignez votre Gmail dans <a href="{{ route('settings.emails') }}">Réglages → Emails</a>,
            ou utilisez « Ouvrir dans ma messagerie » (le PDF est alors à joindre vous-même).
        </div>
    @endunless

    @if ($document && $document->isDraft())
        <div class="alert alert-info">Ce document est un brouillon : en l'envoyant, il sera marqué comme envoyé et recevra son numéro définitif, qui remplacera <code>{numero}</code> dans le message, ainsi que le lien client <code>{lien}</code>.</div>
    @endif

    <form method="POST" action="{{ route('emails.store', array_filter([
        'devis' => $document instanceof \App\Models\Quote ? $document->id : null,
        'facture' => $document instanceof \App\Models\Invoice ? $document->id : null,
        'client' => $document ? null : $client->id,
    ])) }}" data-email-form data-confirm="{{ $document && $document->isDraft() ? 'Envoyer cet email ? Le document recevra son numéro définitif.' : 'Envoyer cet email ?' }}">
        @csrf
        @if ($reminder)<input type="hidden" name="reminder" value="1">@endif

        <div class="card">
            <div class="form-grid cols-2">
                <div class="field span-2">
                    <label for="template">Modèle</label>
                    <select id="template" data-email-template>
                        @foreach ($templates as $template)
                            <option value="{{ $template->id }}" @selected($selected?->id === $template->id)>{{ $template->name }}</option>
                        @endforeach
                    </select>
                    <span class="hint"><a href="{{ route('settings.emails') }}#modeles">Modifier les modèles</a></span>
                </div>
                <x-field name="to" label="Destinataire *" type="text" :value="$client->email" inputmode="email" autocomplete="off" placeholder="adresse@exemple.fr" hint="Plusieurs adresses : séparez-les par une virgule." />
                <x-field name="cc" label="Copie (facultatif)" type="text" inputmode="email" autocomplete="off" />
                <x-field name="subject" label="Objet *" :value="$initial['subject']" class="span-2" />
                <div class="field span-2 @error('body') has-error @enderror">
                    <label for="body">Message *</label>
                    <textarea id="body" name="body" rows="12" data-autogrow>{{ old('body', $initial['body']) }}</textarea>
                </div>
                @if ($document)
                    <div class="field span-2">
                        <label class="check"><input type="checkbox" name="attach_pdf" value="1" @checked(old('attach_pdf', true))>
                            <span>Joindre le PDF « {{ app(\App\Services\PdfService::class)->filename($document) }} »</span></label>
                    </div>
                @endif
                @if ($certificate)
                    <div class="field span-2">
                        <label class="check"><input type="checkbox" name="attach_insurance" value="1" @checked(old('attach_insurance', $document instanceof \App\Models\Quote))>
                            <span>Joindre l'attestation d'assurance décennale (valable jusqu'au {{ $certificate->valid_until->format('d/m/Y') }})</span></label>
                    </div>
                @endif
                @if ($bcc)<p class="small muted span-2" style="margin:0">Une copie cachée est envoyée à {{ $bcc }}.</p>@endif
            </div>
        </div>

        <div class="form-actions sticky-actions">
            <button class="btn" type="submit" @disabled(! $configured)><x-icon name="send" /> Envoyer</button>
            <a class="btn btn-secondary" href="#" data-mailto><x-icon name="mail" /> Ouvrir dans ma messagerie</a>
            <a class="btn btn-secondary" href="{{ $backUrl }}">Annuler</a>
        </div>
    </form>

    <script type="application/json" id="email-templates">@json($rendered)</script>
    <script src="{{ asset('js/email.js') }}?v={{ filemtime(public_path('js/email.js')) }}" defer></script>
@endsection
