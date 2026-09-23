@extends('layouts.app', ['title' => $invoice->fullTitle()])

@php
    use App\Support\Money;

    $meta = [];
    if ($invoice->issue_date) {
        $meta[] = 'Émis'.($invoice->isCredit() ? '' : 'e').' le '.$invoice->issue_date->format('d/m/Y');
    }
    if (! $invoice->isCredit()) {
        $meta[] = $invoice->due_days === 0
            ? 'Payable à réception'
            : ($invoice->due_date ? 'À payer avant le '.$invoice->due_date->format('d/m/Y') : 'Payable sous '.$invoice->due_days.' jours');
    }
    if ($invoice->quote?->number) {
        $meta[] = 'Devis n° '.$invoice->quote->number;
    }
    if ($invoice->cancels) {
        $meta[] = 'Annule la facture n° '.$invoice->cancels->number;
    }
    if ($invoice->corrects) {
        $meta[] = 'Remplace la facture n° '.$invoice->corrects->number;
    }
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $invoice->kindLabel() }} {{ $invoice->displayNumber() }}</h1>
            <p>
                @include('invoices._status')
                @if ($invoice->client)
                    <a href="{{ route('clients.show', $invoice->client) }}">{{ $invoice->client->displayName() }}</a>
                @endif
            </p>
        </div>
    </div>

    @error('send')<div class="alert alert-error" role="alert">{{ $message }}</div>@enderror

    @if ($invoice->status === 'cancelled')
        <div class="alert alert-warning">
            Facture annulée
            @if ($invoice->creditNote) par l'avoir <a href="{{ route('invoices.show', $invoice->creditNote) }}">{{ $invoice->creditNote->number }}</a>@endif
            @if ($invoice->correction) — facture corrigée : <a href="{{ route('invoices.show', $invoice->correction) }}">{{ $invoice->correction->displayNumber() }}</a>@endif.
        </div>
    @endif
    @if ($invoice->cancels)
        <div class="alert alert-info">Cet avoir annule la facture <a href="{{ route('invoices.show', $invoice->cancels) }}">{{ $invoice->cancels->number }}</a>.</div>
    @endif
    @if ($invoice->corrects && $invoice->isDraft())
        <div class="alert alert-info">Correction de la facture <a href="{{ route('invoices.show', $invoice->corrects) }}">{{ $invoice->corrects->number }}</a> (annulée par avoir) : un nouveau numéro sera attribué à l'envoi.</div>
    @endif
    @if ($invoice->isOverdue())
        <div class="alert alert-error">Échéance dépassée depuis le {{ $invoice->due_date->format('d/m/Y') }}.</div>
    @endif
    @foreach ($problems as $problem)
        <div class="alert alert-warning">{{ $problem }}</div>
    @endforeach

    <div class="action-bar">
        @if ($invoice->status !== 'cancelled' && ($problems ?? []) === [])
            <a class="btn" href="{{ route('emails.create', ['facture' => $invoice->id]) }}"><x-icon name="mail" /> Envoyer par email</a>
        @endif
        <a class="btn btn-secondary" href="{{ route('invoices.pdf', $invoice) }}" target="_blank" rel="noopener"><x-icon name="file" /> PDF</a>
        @if ($invoice->isDraft() && ! $invoice->isCredit())
            <a class="btn" href="{{ route('invoices.edit', $invoice) }}"><x-icon name="file" /> Modifier</a>
            <form method="POST" action="{{ route('invoices.send', $invoice) }}" data-confirm="Marquer cette facture comme envoyée ? Elle recevra son numéro définitif et ne sera plus modifiable directement.">
                @csrf
                <button class="btn btn-secondary" type="submit"><x-icon name="send" /> Marquer comme envoyée</button>
            </form>
        @endif
        @if ($invoice->isCorrectable())
            <form method="POST" action="{{ route('invoices.correct', $invoice) }}" data-confirm="Modifier cette facture ? Un avoir sera émis pour l'annuler (obligation légale) et une copie modifiable sera préparée ; elle recevra un nouveau numéro à l'envoi.">
                @csrf
                <button class="btn" type="submit"><x-icon name="file" /> Modifier</button>
            </form>
            <button class="btn btn-secondary" type="button" data-open-sheet="cancel-dialog"><x-icon name="x" /> Annuler par avoir</button>
        @endif
    </div>

    <article class="doc">
        @include('documents._preview', [
            'document' => $invoice,
            'docTitle' => $invoice->fullTitle(),
            'meta' => $meta,
            'grandLabel' => $invoice->isCredit() ? 'Montant de l\'avoir' : null,
        ])

        <div class="doc-footer">
            @if (! $invoice->isCredit() && $invoice->amount_paid > 0)
                <p><strong>Déjà réglé :</strong> {{ Money::format($invoice->amount_paid) }} — <strong>reste à payer :</strong> {{ Money::format($invoice->balance()) }}</p>
            @endif
            @if ($invoice->work_period)<p><strong>Date des travaux :</strong> {{ $invoice->work_period }}</p>@endif
            @if ($invoice->payment_terms)<p><strong>Conditions de paiement :</strong> {{ $invoice->payment_terms }}</p>@endif
            @if ($invoice->notes)<p>{!! nl2br(e($invoice->notes)) !!}</p>@endif
            @include('documents._mentions', ['document' => $invoice])
        </div>
    </article>

    @if ($invoice->internal_notes)
        <div class="card">
            <h2>Notes internes</h2>
            <p class="pre-line" style="margin:0">{{ $invoice->internal_notes }}</p>
        </div>
    @endif

    @include('documents._history')

    @if ($invoice->isDraft())
        <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" data-confirm="Mettre ce brouillon à la corbeille ?">
            @csrf
            @method('DELETE')
            <div class="form-actions"><button class="btn btn-danger-outline" type="submit">Mettre le brouillon à la corbeille</button></div>
        </form>
    @endif

    @if ($invoice->isCorrectable())
        <dialog class="sheet" id="cancel-dialog" aria-labelledby="cancel-title">
            <form method="POST" action="{{ route('invoices.cancel', $invoice) }}">
                @csrf
                <div class="card-head">
                    <h2 id="cancel-title">Annuler la facture</h2>
                    <button class="icon-btn" type="button" data-close-sheet><x-icon name="x" /><span class="visually-hidden">Fermer</span></button>
                </div>
                <p class="small muted">Une facture envoyée ne peut pas être supprimée : un avoir du même montant ({{ Money::format($invoice->total_ttc) }}) va être émis pour l'annuler.</p>
                <div class="field">
                    <label for="reason">Motif (facultatif)</label>
                    <input id="reason" name="reason" type="text" maxlength="300" placeholder="ex. travaux annulés, erreur de client…">
                </div>
                <div class="form-actions"><button class="btn btn-danger" type="submit">Émettre l'avoir</button></div>
            </form>
        </dialog>
    @endif
@endsection
