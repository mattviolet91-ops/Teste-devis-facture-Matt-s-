@extends('layouts.app', ['title' => 'Planning'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Planning</h1>
            <p>{{ $mode === 'mois' ? ucfirst($from->locale('fr')->isoFormat('MMMM YYYY')) : 'Semaine du '.$from->locale('fr')->isoFormat('D MMMM').' au '.$to->locale('fr')->isoFormat('D MMMM YYYY') }}</p>
        </div>
        <div class="action-bar" style="margin:0">
            <a class="btn" href="{{ route('planning.create', ['type' => 'rdv']) }}"><x-icon name="plus" /> Rendez-vous</a>
            <a class="btn btn-secondary" href="{{ route('planning.create') }}"><x-icon name="plus" /> Chantier</a>
        </div>
    </div>

    <div class="period-bar">
        <div class="chips" role="group" aria-label="Navigation">
            <a class="chip" href="{{ route('planning.index', ['vue' => $mode, 'date' => $previous]) }}" aria-label="Précédent">←</a>
            <a class="chip" href="{{ route('planning.index', ['vue' => $mode]) }}">Aujourd'hui</a>
            <a class="chip" href="{{ route('planning.index', ['vue' => $mode, 'date' => $next]) }}" aria-label="Suivant">→</a>
            <a class="chip {{ $mode === 'semaine' ? 'is-active' : '' }}" href="{{ route('planning.index', ['vue' => 'semaine', 'date' => $from->toDateString()]) }}">Semaine</a>
            <a class="chip {{ $mode === 'mois' ? 'is-active' : '' }}" href="{{ route('planning.index', ['vue' => 'mois', 'date' => $from->toDateString()]) }}">Mois</a>
        </div>
    </div>

    <div class="planning">
        @forelse ($days as $day)
            <section class="planning-day {{ $day['date']->isToday() ? 'is-today' : '' }} {{ $day['date']->isWeekend() && $day['items']->isEmpty() ? 'is-weekend' : '' }}">
                <div class="planning-date">
                    <strong>{{ ucfirst($day['date']->locale('fr')->isoFormat('ddd D')) }}</strong>
                    <a class="small" href="{{ route('planning.create', ['date' => $day['date']->toDateString(), 'type' => 'rdv']) }}" aria-label="Ajouter un rendez-vous ce jour">+</a>
                </div>
                <div class="planning-items">
                    @foreach ($day['items'] as $item)
                        <a class="planning-item kind-{{ $item->kind }} status-{{ $item->status }}" href="{{ route('planning.show', $item) }}">
                            <strong>{{ $item->start_time && $item->starts_on->isSameDay($day['date']) ? ($item->isAppointment() ? $item->timeRange() : $item->timeLabel()).' · ' : '' }}{{ $item->heading() }}</strong>
                            <span class="small">{{ $item->isAppointment() ? 'RDV · ' : '' }}{{ $item->client ? $item->title : '' }}{{ $item->days() > 1 ? ' (jour '.((int) $item->starts_on->diffInDays($day['date']) + 1).'/'.$item->days().')' : '' }}</span>
                            @if ($item->address())<span class="small muted">{{ $item->location ?: ($item->worksite?->city ?? $item->client?->city) }}</span>@endif
                        </a>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="card empty"><x-icon name="calendar" /><h2>Rien de prévu ce mois-ci</h2></div>
        @endforelse
    </div>

    @if ($toPlan->isNotEmpty())
        <div class="card" style="margin-top:1rem">
            <div class="card-head"><h2>Devis acceptés à planifier</h2></div>
            <ul class="stat-list">
                @foreach ($toPlan as $quote)
                    <li>
                        <a href="{{ route('quotes.show', $quote) }}">{{ $quote->number }} · {{ $quote->client->displayName() }}</a>
                        <a class="btn btn-secondary btn-sm" href="{{ route('planning.create', ['devis' => $quote->id]) }}">Planifier</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
