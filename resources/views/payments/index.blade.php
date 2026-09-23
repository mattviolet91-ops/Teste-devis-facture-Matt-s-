@extends('layouts.app', ['title' => 'Paiements'])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Paiements</h1>
            <p>Encaissé du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }} : <strong>{{ Money::format($total) }}</strong></p>
        </div>
    </div>

    <form method="GET" action="{{ route('payments.index') }}" class="card filters">
        <div class="form-grid cols-2">
            <div class="field"><label for="du">Du</label><input id="du" type="date" name="du" value="{{ $from->toDateString() }}"></div>
            <div class="field"><label for="au">Au</label><input id="au" type="date" name="au" value="{{ $to->toDateString() }}"></div>
            <div class="field">
                <label for="mode">Moyen de paiement</label>
                <select id="mode" name="mode">
                    <option value="">Tous</option>
                    @foreach (\App\Models\Payment::METHODS as $key => $label)
                        <option value="{{ $key }}" @selected($mode === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="align-self:end"><button class="btn btn-secondary" type="submit">Afficher</button></div>
        </div>
        <div class="chips" style="margin-top:.5rem">
            <a class="chip" href="{{ route('payments.index', ['du' => today()->startOfMonth()->toDateString(), 'au' => today()->toDateString()]) }}">Ce mois</a>
            <a class="chip" href="{{ route('payments.index', ['du' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'au' => today()->subMonthNoOverflow()->endOfMonth()->toDateString()]) }}">Mois dernier</a>
            <a class="chip" href="{{ route('payments.index', ['du' => today()->startOfYear()->toDateString(), 'au' => today()->toDateString()]) }}">Cette année</a>
        </div>
    </form>

    @if ($byMethod->isNotEmpty())
        <div class="card">
            <h2>Par moyen de paiement</h2>
            <ul class="stat-list">
                @foreach ($byMethod as $row)
                    <li><span>{{ \App\Models\Payment::METHODS[$row->method] ?? $row->method }} <span class="muted small">({{ $row->count }})</span></span><strong>{{ Money::format((int) $row->total) }}</strong></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($openInvoices->isNotEmpty())
        <div class="card">
            <h2>Encaisser une facture</h2>
            <ul class="stat-list">
                @foreach ($openInvoices as $invoice)
                    <li>
                        <a href="{{ route('invoices.show', ['invoice' => $invoice, 'encaisser' => 1]) }}#paiements">{{ $invoice->number }} · {{ $invoice->client?->displayName() }}</a>
                        <span>@include('invoices._status') <strong>{{ Money::format($invoice->balance()) }}</strong></span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($payments->isEmpty())
        <div class="card empty"><x-icon name="wallet" /><h2>Aucun paiement sur cette période</h2></div>
    @else
        <ul class="list">
            @foreach ($payments as $payment)
                <li>
                    <a class="list-item" href="{{ route('invoices.show', $payment->invoice) }}#paiements">
                        <span class="list-main">
                            <strong>{{ $payment->client?->displayName() }}</strong>
                            <span class="muted small">{{ $payment->paid_at->format('d/m/Y') }} · {{ $payment->methodLabel() }} · facture {{ $payment->invoice?->number }}</span>
                        </span>
                        <span class="list-meta"><strong class="amount">{{ Money::format($payment->amount) }}</strong></span>
                    </a>
                </li>
            @endforeach
        </ul>
        {{ $payments->links('components.pagination') }}
    @endif
@endsection
