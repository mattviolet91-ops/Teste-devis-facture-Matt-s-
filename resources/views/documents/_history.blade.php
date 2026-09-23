<div class="card">
    <div class="card-head"><h2>Historique</h2></div>
    <ol class="timeline">
        @foreach ($history as $event)
            <li><span class="muted small">{{ $event->created_at->format('d/m/Y H:i') }}</span><span>{{ $event->description }}</span></li>
        @endforeach
    </ol>
</div>
