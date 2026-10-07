@extends('layouts.app', ['title' => 'Frais du chantier'])

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $job['title'] }}</h1>
            <p><strong>{{ $job['client']?->displayName() }}</strong>@if ($job['address'])<br><span class="muted">{{ $job['address'] }}</span>@endif</p>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>{{ $job['fully'] ? 'Facturé entièrement' : ($job['billed'] ? 'Facturé en partie ('.$job['percent'].' %)' : 'En cours, pas encore facturé') }}</h2>
            <span class="badge">Visible par vous seul</span>
        </div>
        <p class="small" style="margin-top:0">
            @foreach ($job['quotes'] as $quote)<a href="{{ route('quotes.show', $quote) }}">Devis {{ $quote->number }}</a> · @endforeach
            @foreach ($job['invoices'] as $invoice)<a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->kindLabel() }} {{ $invoice->number }}</a>@if (! $loop->last) · @endif @endforeach
        </p>
        @include('expenses._panel', [
            'job' => $job,
            'action' => route('expenses.project.store', $job['project']),
            'open' => $job['expenses']->isEmpty(),
        ])
    </div>

    <details class="card">
        <summary>Nom du chantier</summary>
        <form method="POST" action="{{ route('expenses.project.rename', $job['project']) }}" class="form-grid" style="margin-top:.75rem">
            @csrf
            @method('PUT')
            <x-field name="title" label="Nom" :value="$job['title']" required hint="Utile quand le chantier regroupe plusieurs devis, ex. « Toiture de la maison »." />
            <div><button class="btn btn-secondary" type="submit">Renommer</button></div>
        </form>
        <p class="small muted" style="margin-bottom:0">Pour ajouter un autre devis du même client à ce chantier : ouvrez ce devis, section « Frais du chantier », puis « Ranger le devis dans un autre chantier ».</p>
    </details>
@endsection
