@extends('layouts.app', ['title' => 'Devis'])

@php use App\Support\Money; @endphp

@section('content')
    @include('documents._switch', ['current' => 'quotes'])
    <div class="page-head">
        <h1>Devis</h1>
        <a class="btn" href="{{ route('quotes.create') }}"><x-icon name="plus" /> Nouveau devis</a>
    </div>

    <form method="GET" action="{{ route('quotes.index') }}" class="card filters">
        <label class="search-field" for="q">
            <x-icon name="search" /><span class="visually-hidden">Rechercher un devis</span>
            <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="N° de devis, client, objet, adresse…" autocomplete="off">
        </label>
        <input type="hidden" name="status" value="{{ $filters['status'] }}">
        <div class="chips" role="group" aria-label="Statut">
            @foreach (\App\Http\Controllers\QuoteController::FILTERS as $key => $label)
                <a class="chip {{ $filters['status'] === $key ? 'is-active' : '' }}" href="{{ route('quotes.index', array_filter(['status' => $key, 'q' => $filters['q'] ?? null])) }}">{{ $label }}</a>
            @endforeach
        </div>
    </form>

    @if ($quotes->isEmpty())
        <div class="card empty">
            <x-icon name="file" />
            <h2>Aucun devis</h2>
            <p><a class="btn" href="{{ route('quotes.create') }}">Créer un devis</a></p>
        </div>
    @else
        <ul class="list">
            @foreach ($quotes as $quote)
                <li>
                    <a class="list-item" href="{{ route('quotes.show', $quote) }}">
                        <span class="list-main">
                            <strong>{{ $quote->displayNumber() }} · {{ $quote->client?->displayName() }}</strong>
                            <span class="muted small">{{ $quote->title ?: 'Sans objet' }} · {{ ($quote->issue_date ?? $quote->updated_at)->format('d/m/Y') }}</span>
                        </span>
                        <span class="list-meta">
                            <strong class="amount">{{ Money::format($quote->total_ttc) }}</strong>
                            @include('quotes._status')
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        {{ $quotes->links('components.pagination') }}
    @endif
@endsection
