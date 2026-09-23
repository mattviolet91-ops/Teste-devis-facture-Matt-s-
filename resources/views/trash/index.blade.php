@extends('layouts.app', ['title' => 'Corbeille'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Corbeille</h1>
            <p>Les éléments sont supprimés définitivement {{ $retention }} jours après leur mise à la corbeille.</p>
        </div>
    </div>

    @if ($clients->isEmpty() && $worksites->isEmpty())
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
