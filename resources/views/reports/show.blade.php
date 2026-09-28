@extends('layouts.app', ['title' => 'Rapport d\'intervention'])

@php
    $client = $report->client;
    $phone = preg_replace('/\D/', '', (string) $client->phone);
    $whatsappPhone = preg_match('/^0\d{9}$/', $phone) ? '33'.substr($phone, 1) : $phone;
    $shareMessage = str_replace('{lien}', $report->publicUrl(), $message);
    $paragraphs = fn (?string $text) => collect(preg_split('/\R{2,}/', trim((string) $text)))->filter(fn ($p) => trim($p) !== '');
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $report->title }}</h1>
            <p><span class="badge">Rapport d'intervention</span>
                <a href="{{ route('clients.show', $client) }}">{{ $client->displayName() }}</a>
                · {{ $report->visit_date->format('d/m/Y') }}
                @if ($report->sent_at)<span class="badge badge-success">Envoyé le {{ $report->sent_at->format('d/m') }}</span>@endif</p>
        </div>
        <div class="action-bar" style="margin:0">
            <a class="btn" href="{{ route('reports.pdf', $report) }}" target="_blank" rel="noopener"><x-icon name="file" /> Voir le PDF</a>
            <a class="btn btn-secondary" href="{{ route('reports.edit', $report) }}">Modifier</a>
        </div>
    </div>

    <div class="card">
        @if ($report->address())<p class="muted small" style="margin-top:0">{{ $report->address() }}</p>@endif
        <h2>Constat</h2>
        @foreach ($paragraphs($report->findings) as $p)<p class="pre-line">{{ trim($p) }}</p>@endforeach
        @if (trim((string) $report->work_done) !== '')
            <h2>Travaux réalisés</h2>
            @foreach ($paragraphs($report->work_done) as $p)<p class="pre-line">{{ trim($p) }}</p>@endforeach
        @endif
        @if (trim((string) $report->recommendations) !== '')
            <h2>Préconisations</h2>
            @foreach ($paragraphs($report->recommendations) as $p)<p class="pre-line">{{ trim($p) }}</p>@endforeach
        @endif
    </div>

    @include('documents._photos', ['document' => $report, 'route' => 'reports.photos'])

    <div class="card" data-share data-track="{{ route('reports.shared', $report) }}">
        @csrf
        <h2>Envoyer au client</h2>
        <p class="muted small">Le lien ouvre le PDF du rapport, sans connexion. Message modifiable.</p>
        <div class="field">
            <label for="report-message">Message</label>
            <textarea id="report-message" rows="7" data-share-message>{{ $shareMessage }}</textarea>
        </div>
        <div class="share-buttons">
            <a class="btn" href="#" data-share-to="whatsapp" data-phone="{{ $whatsappPhone }}" @if (! $phone) aria-disabled="true" @endif><x-icon name="message" /> WhatsApp</a>
            <a class="btn" href="#" data-share-to="sms" data-phone="{{ $phone }}"><x-icon name="message" /> SMS</a>
            <button class="btn btn-secondary" type="button" data-share-to="copy"><x-icon name="copy" /> Copier</button>
        </div>
    </div>

    <form method="POST" action="{{ route('reports.email', $report) }}" class="card">
        @csrf
        <h2>Par email (PDF joint)</h2>
        @if ($mailConfigured)
            <div class="form-grid">
                <x-field name="to" label="Adresse email" type="email" :value="$client->email" required />
                <x-field name="message" label="Message" type="textarea" rows="7" :value="$message" required hint="{lien} est remplacé par le lien du rapport." />
            </div>
            <div class="action-bar"><button class="btn" type="submit"><x-icon name="send" /> Envoyer le rapport</button></div>
        @else
            <p class="muted" style="margin:0">L'envoi d'emails n'est pas encore configuré (Réglages → Emails). Utilisez WhatsApp, SMS ou Copier ci-dessus.</p>
        @endif
    </form>

    <form method="POST" action="{{ route('reports.destroy', $report) }}" data-confirm="Supprimer ce rapport ? Les photos restent sur la fiche du client.">
        @csrf
        @method('DELETE')
        <button class="btn btn-secondary" type="submit"><x-icon name="trash" /> Supprimer le rapport</button>
    </form>
@endsection
