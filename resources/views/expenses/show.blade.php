@extends('layouts.app', ['title' => 'Frais du chantier'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Frais du chantier</h1>
            <p><strong>{{ $job['client']?->displayName() }}</strong> · {{ $job['title'] }}@if ($job['address'])<br><span class="muted">{{ $job['address'] }}</span>@endif</p>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>{{ $job['fully'] ? 'Facturé entièrement' : 'Facturé en partie ('.$job['percent'].' %)' }}</h2>
            <span class="badge">Visible par vous seul</span>
        </div>
        <p class="small" style="margin-top:0">
            @if ($job['quote'])<a href="{{ route('quotes.show', $job['quote']) }}">Devis {{ $job['quote']->number }}</a> · @endif
            @foreach ($job['invoices'] as $invoice)
                <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->kindLabel() }} {{ $invoice->number }}</a>@if (! $loop->last) · @endif
            @endforeach
        </p>
        @include('expenses._panel', [
            'job' => $job,
            'action' => $job['quote'] ? route('expenses.quote.store', $job['quote']) : route('expenses.store', $job['invoice']),
            'retour' => $job['quote'] ? null : 'chantier',
            'open' => $job['expenses']->isEmpty(),
        ])
    </div>
@endsection
