@extends('layouts.app', ['title' => 'Devis express'])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Devis express</h1>
            <p>Écrivez ou dictez le devis en une phrase : l'application prépare le brouillon.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('quotes.express.preview') }}" class="card">
        @csrf
        <x-field name="text" label="Client, puis les prestations" type="textarea" rows="5" :value="$text" required
            placeholder="Mme Martin Massy — démoussage 120 m² à 12 € — faîtage 15 ml à 45 € — évacuation forfait 150 €"
            hint="Commencez par le client. Séparez les prestations par un tiret, un point-virgule ou un retour à la ligne. Pour chacune : quantité + unité (m², ml, u, h, forfait) et prix « à … € ». Astuce : le micro du clavier permet de dicter." />
        <div class="action-bar"><button class="btn" type="submit"><x-icon name="check" /> Voir l'aperçu</button></div>
    </form>

    @if ($parsed)
        <form method="POST" action="{{ route('quotes.express.store') }}" class="card">
            @csrf
            <input type="hidden" name="text" value="{{ $text }}">
            <h2>Aperçu</h2>

            @foreach ($parsed['errors'] as $error)
                <div class="alert alert-error">{{ $error }}</div>
            @endforeach

            @if ($parsed['client'])
                <p><strong>Client :</strong> <a href="{{ route('clients.show', $parsed['client']) }}">{{ $parsed['client']->displayName() }}</a>{{ $parsed['client']->city ? ' · '.$parsed['client']->city : '' }}</p>
                <input type="hidden" name="client_id" value="{{ $parsed['client']->id }}">
            @elseif ($parsed['clients']->isNotEmpty())
                <div class="field">
                    <label for="client_id">Quel client ?</label>
                    <select id="client_id" name="client_id" required>
                        @foreach ($parsed['clients'] as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->displayName() }}{{ $candidate->city ? ' — '.$candidate->city : '' }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <p><a class="btn btn-secondary btn-sm" href="{{ route('clients.create') }}"><x-icon name="plus" /> Créer la fiche client</a></p>
            @endif

            <x-field name="title" label="Objet du devis" :value="$parsed['title']" />

            @if ($parsed['lines'])
                <ul class="express-lines">
                    @foreach ($parsed['lines'] as $line)
                        <li>
                            <div class="express-line-head">
                                <strong>{{ $line['title'] ?: '—' }}</strong>
                                @if ($line['recognized'])<span class="badge badge-success">Bibliothèque</span>@endif
                            </div>
                            <div class="express-line-calc">
                                <span>{{ str_replace('.', ',', $line['quantity']) }} {{ $line['unit'] }} × {{ $line['unit_price_cents'] !== null ? Money::format($line['unit_price_cents']) : '? €' }}</span>
                                <strong>{{ $line['total'] !== null ? Money::format($line['total']) : '—' }}</strong>
                            </div>
                            @foreach ($line['warnings'] as $warning)<div class="small {{ $line['blocking'] ? 'text-danger' : 'muted' }}">{{ ucfirst($warning) }}</div>@endforeach
                        </li>
                    @endforeach
                </ul>
                <p class="express-total"><span>Total HT</span><strong>{{ Money::format((int) collect($parsed['lines'])->sum('total')) }}</strong></p>
            @endif

            @if ($parsed['errors'] === [] || (! $parsed['client'] && $parsed['clients']->isNotEmpty() && count($parsed['errors']) === 1))
                <div class="action-bar"><button class="btn" type="submit"><x-icon name="file" /> Créer le brouillon</button></div>
                <p class="small muted">Le devis est créé en brouillon : vous pourrez tout modifier, puis l'envoyer.</p>
            @else
                <p class="small muted">Corrigez le texte ci-dessus, puis « Voir l'aperçu » à nouveau.</p>
            @endif
        </form>
    @endif
@endsection
