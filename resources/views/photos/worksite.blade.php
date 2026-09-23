@extends('layouts.app', ['title' => 'Photos du chantier'])

@section('content')
    <div class="page-head">
        <div>
            <h1>Photos du chantier</h1>
            <p><a href="{{ route('clients.show', $worksite->client) }}#chantier-{{ $worksite->id }}">{{ $worksite->client->displayName() }}</a> · {{ $worksite->fullAddress() }}</p>
        </div>
    </div>

    @error('photos')<div class="alert alert-error" role="alert">{{ $message }}</div>@enderror
    @error('photos.*')<div class="alert alert-error" role="alert">{{ $message }}</div>@enderror

    @include('photos._upload')

    @if ($photos->isEmpty())
        <div class="card empty"><x-icon name="camera" /><h2>Aucune photo pour ce chantier</h2></div>
    @else
        @foreach (\App\Models\Photo::CATEGORIES as $key => $label)
            @php $group = $photos->where('category', $key); @endphp
            @if ($group->isNotEmpty())
                <h2 class="section-title">{{ $label }} <span class="muted small">({{ $group->count() }})</span></h2>
                @include('photos._grid', ['photos' => $group, 'manage' => true])
            @endif
        @endforeach
    @endif

    @include('photos._annotator')
@endsection
