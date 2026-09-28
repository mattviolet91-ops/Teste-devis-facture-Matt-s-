@extends('layouts.app', ['title' => 'Factures'])

@php use App\Support\Money; @endphp

@section('content')
    @include('documents._switch', ['current' => 'invoices'])
    <div class="page-head">
        <h1>Factures</h1>
        <a class="btn" href="{{ route('invoices.create') }}"><x-icon name="plus" /> Nouvelle facture</a>
    </div>

    <form method="GET" action="{{ route('invoices.index') }}" class="card filters">
        <label class="search-field" for="q">
            <x-icon name="search" /><span class="visually-hidden">Rechercher une facture</span>
            <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="N° de facture ou de devis, client, adresse…" autocomplete="off">
        </label>
        <input type="hidden" name="status" value="{{ $filters['status'] }}">
        <div class="chips" role="group" aria-label="Statut">
            @foreach (\App\Http\Controllers\InvoiceController::FILTERS as $key => $label)
                <a class="chip {{ $filters['status'] === $key ? 'is-active' : '' }}" href="{{ route('invoices.index', array_filter(['status' => $key, 'q' => $filters['q'] ?? null])) }}">{{ $label }}</a>
            @endforeach
        </div>
    </form>

    @if ($invoices->isEmpty())
        <div class="card empty">
            <x-icon name="receipt" />
            <h2>Aucune facture</h2>
            <p class="muted">Ouvrez un devis accepté et appuyez sur « Facturer », ou créez une facture libre.</p>
            <p><a class="btn" href="{{ route('invoices.create') }}">Créer une facture</a></p>
        </div>
    @else
        <ul class="list">
            @foreach ($invoices as $invoice)
                @php $open = ! $invoice->isCredit() && in_array($invoice->status, \App\Models\Invoice::OPEN, true); @endphp
                <li class="swipe" @if ($open) data-swipe @endif>
                    @if ($open)
                        <div class="swipe-actions">
                            <a class="swipe-action swipe-ok" href="{{ route('invoices.show', ['invoice' => $invoice, 'encaisser' => 1]) }}#paiements" tabindex="-1"><x-icon name="wallet" /> Encaisser</a>
                            <a class="swipe-action" href="{{ route('reminders.show', $invoice) }}" tabindex="-1"><x-icon name="send" /> Relancer</a>
                        </div>
                    @endif
                    <a class="list-item swipe-content" href="{{ route('invoices.show', $invoice) }}">
                        <span class="list-main">
                            <strong>{{ $invoice->displayNumber() }} · {{ $invoice->client?->displayName() }}</strong>
                            <span class="muted small">{{ $invoice->kindLabel() }}{{ $invoice->title ? ' · '.$invoice->title : '' }} · {{ ($invoice->issue_date ?? $invoice->updated_at)->format('d/m/Y') }}</span>
                        </span>
                        <span class="list-meta">
                            <strong class="amount">{{ $invoice->isCredit() ? '− ' : '' }}{{ Money::format($invoice->total_ttc) }}</strong>
                            @include('invoices._status')
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        {{ $invoices->links('components.pagination') }}
    @endif
@endsection
