@extends('layouts.app', ['title' => 'Corbeille'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Corbeille</h1>
            <p>Les éléments sont supprimés définitivement {{ $retention }} jours après leur mise à la corbeille.</p>
        </div>
    </div>

    @if ($clients->isEmpty() && $worksites->isEmpty() && $quotes->isEmpty() && $invoices->isEmpty())
        <div class="card empty"><x-icon name="trash" /><h2>La corbeille est vide</h2></div>
    @endif

    @if ($clients->isNotEmpty())
        <div class="card">
            <h2>Clients</h2>
            <ul class="stat-list">
                @foreach ($clients as $client)
                    <li>
                        <span>
                            <strong>{{ $client->displayName() }}</strong><br>
                            <span class="muted small">Supprimé le {{ $client->deleted_at->format('d/m/Y') }} — effacement le {{ $client->deleted_at->addDays($retention)->format('d/m/Y') }}</span>
                        </span>
                        <form method="POST" action="{{ route('trash.clients.restore', $client->id) }}">
                            @csrf
                            <button class="btn btn-secondary btn-sm" type="submit">Restaurer</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($quotes->isNotEmpty())
        <div class="card">
            <h2>Brouillons de devis</h2>
            <ul class="stat-list">
                @foreach ($quotes as $quote)
                    <li>
                        <span>
                            <strong>{{ $quote->title ?: 'Devis sans objet' }}</strong><br>
                            <span class="muted small">{{ $quote->client?->displayName() }} — supprimé le {{ $quote->deleted_at->format('d/m/Y') }}</span>
                        </span>
                        <form method="POST" action="{{ route('trash.quotes.restore', $quote->id) }}">
                            @csrf
                            <button class="btn btn-secondary btn-sm" type="submit">Restaurer</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($invoices->isNotEmpty())
        <div class="card">
            <h2>Brouillons de factures</h2>
            <ul class="stat-list">
                @foreach ($invoices as $invoice)
                    <li>
                        <span>
                            <strong>{{ $invoice->kindLabel() }}{{ $invoice->title ? ' — '.$invoice->title : '' }}</strong><br>
                            <span class="muted small">{{ $invoice->client?->displayName() }} — supprimé le {{ $invoice->deleted_at->format('d/m/Y') }}</span>
                        </span>
                        <form method="POST" action="{{ route('trash.invoices.restore', $invoice->id) }}">
                            @csrf
                            <button class="btn btn-secondary btn-sm" type="submit">Restaurer</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($worksites->isNotEmpty())
        <div class="card">
            <h2>Chantiers</h2>
            <ul class="stat-list">
                @foreach ($worksites as $worksite)
                    <li>
                        <span>
                            <strong>{{ $worksite->fullAddress() }}</strong><br>
                            <span class="muted small">{{ $worksite->client->displayName() }} — supprimé le {{ $worksite->deleted_at->format('d/m/Y') }}</span>
                        </span>
                        <form method="POST" action="{{ route('trash.worksites.restore', $worksite->id) }}">
                            @csrf
                            <button class="btn btn-secondary btn-sm" type="submit">Restaurer</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
