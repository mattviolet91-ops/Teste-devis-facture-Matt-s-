@extends('layouts.app', ['title' => 'Relancer '.$invoice->number])

@php
    use App\Support\Money;
    $phone = preg_replace('/\D/', '', (string) $invoice->client?->phone);
    $whatsappPhone = preg_match('/^0\d{9}$/', $phone) ? '33'.substr($phone, 1) : $phone;
    $late = $invoice->due_date ? (int) $invoice->due_date->diffInDays(today(), false) : 0;
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Relancer {{ $invoice->client?->displayName() }}</h1>
            <p><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->kindLabel() }} {{ $invoice->number }}</a></p>
        </div>
    </div>

    <div class="card">
        <ul class="stat-list">
            <li><span>Reste à payer</span><strong>{{ Money::format($invoice->balance()) }}</strong></li>
            <li><span>Échéance</span>
                <strong>{{ $invoice->due_date?->format('d/m/Y') }}
                    @if ($late > 0)<span class="badge badge-danger">en retard de {{ $late }} jour{{ $late > 1 ? 's' : '' }}</span>
                    @elseif ($late === 0)<span class="badge badge-warning">aujourd'hui</span>
                    @else<span class="badge badge-info">dans {{ -$late }} jour{{ -$late > 1 ? 's' : '' }}</span>@endif
                </strong></li>
            <li><span>Relances déjà faites</span><strong>{{ $invoice->reminder_count }}{{ $invoice->last_reminder_at ? ' (dernière le '.$invoice->last_reminder_at->format('d/m/Y').')' : '' }}</strong></li>
        </ul>
    </div>

    <div class="card" data-share data-track="{{ route('reminders.track', $invoice) }}">
        @csrf
        <h2>{{ $overdue ? 'Message de relance' : 'Rappel avant échéance' }}</h2>
        <p class="muted small">Texte prêt, modifiable avant l'envoi (modèle dans <a href="{{ route('settings.emails') }}#sms">Réglages → Emails</a>). Chaque envoi est compté.</p>
        <div class="field">
            <label for="reminder-message">Message</label>
            <textarea id="reminder-message" rows="8" data-share-message>{{ $message }}</textarea>
        </div>
        <div class="share-buttons">
            <a class="btn" href="#" data-share-to="whatsapp" data-phone="{{ $whatsappPhone }}" @if (! $phone) aria-disabled="true" @endif><x-icon name="message" /> WhatsApp</a>
            <a class="btn" href="#" data-share-to="sms" data-phone="{{ $phone }}"><x-icon name="message" /> SMS</a>
            <a class="btn" href="{{ route('emails.create', ['facture' => $invoice->id, 'relance' => 1]) }}"><x-icon name="mail" /> Email (avec la facture)</a>
            <button class="btn btn-secondary" type="button" data-share-to="copy"><x-icon name="copy" /> Copier</button>
        </div>
        @unless ($phone)<p class="small muted">Pas de numéro de téléphone pour ce client : <a href="{{ route('clients.edit', $invoice->client) }}">l'ajouter</a>.</p>@endunless
    </div>
@endsection
