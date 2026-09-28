{{-- Actions principales toujours visibles en bas de l'écran (téléphone). Attend : $actions = [[libellé, icône, url, attributs?], …] --}}
@if (! empty($actions))
    <div class="quick-bar-spacer" aria-hidden="true"></div>
    <nav class="quick-bar" aria-label="Actions principales">
        @foreach (array_slice($actions, 0, 3) as $i => [$label, $icon, $url, $attrs])
            @if ($url)
                <a class="btn {{ $i === 0 ? '' : 'btn-secondary' }}" href="{{ $url }}"><x-icon :name="$icon" /> {{ $label }}</a>
            @else
                <button class="btn {{ $i === 0 ? '' : 'btn-secondary' }}" type="button" {!! $attrs !!}><x-icon :name="$icon" /> {{ $label }}</button>
            @endif
        @endforeach
    </nav>
@endif
