@extends('layouts.app', ['title' => 'Prestations'])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Bibliothèque de prestations</h1>
            <p>Reprises dans l'éditeur de devis en un clic. Les devis existants ne changent pas si vous modifiez une prestation.</p>
        </div>
        <a class="btn" href="{{ route('catalog.create') }}"><x-icon name="plus" /> Nouvelle prestation</a>
    </div>

    <form method="GET" action="{{ route('catalog.index') }}" class="card filters">
        <label class="search-field" for="q">
            <x-icon name="search" /><span class="visually-hidden">Rechercher</span>
            <input id="q" type="search" name="q" value="{{ $q }}" placeholder="Démoussage, faîtière, Velux, zinc…">
        </label>
    </form>

    @forelse ($groups as $category => $items)
        <h2 class="section-title">{{ $category }}</h2>
        <ul class="list">
            @foreach ($items as $item)
                <li>
                    <a class="list-item" href="{{ route('catalog.edit', $item) }}">
                        <span class="list-main">
                            <strong>{{ $item->name }}</strong>
                            <span class="muted small">{{ \Illuminate\Support\Str::limit(str_replace("\n", ' · ', (string) $item->description), 110) }}</span>
                        </span>
                        <span class="list-meta">
                            <strong class="amount">{{ $item->unit_price ? Money::format($item->unit_price) : 'Prix à saisir' }}</strong>
                            <span class="muted small">/ {{ $item->unit }}</span>
                            @unless ($item->is_active)<span class="badge">Masquée</span>@endunless
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @empty
        <div class="card empty"><x-icon name="book" /><h2>Aucune prestation{{ $q ? ' pour « '.$q.' »' : '' }}</h2></div>
    @endforelse

    <div class="card">
        <h2>Catégories</h2>
        @foreach ($categories as $category)
            <form method="POST" action="{{ route('catalog.categories.update', $category) }}" class="row-form unit">
                @csrf
                @method('PUT')
                <div class="field"><label for="category-{{ $category->id }}">Nom</label><input id="category-{{ $category->id }}" type="text" name="name" value="{{ $category->name }}" required></div>
                <span class="muted small">{{ $category->items_count }} prestation(s)</span>
                <span></span>
                <button class="btn btn-secondary btn-sm" type="submit">Renommer</button>
            </form>
        @endforeach
        <form method="POST" action="{{ route('catalog.categories.store') }}" class="form-grid cols-2" style="margin-top:1rem">
            @csrf
            <div class="field"><label for="new-category">Nouvelle catégorie</label><input id="new-category" type="text" name="name" required></div>
            <div style="align-self:end"><button class="btn btn-secondary" type="submit">Ajouter</button></div>
        </form>
    </div>
@endsection
