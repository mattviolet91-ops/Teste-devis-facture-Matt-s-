@extends('layouts.app', ['title' => $title])

@section('content')
    <div class="page-head"><h1>{{ $title }}</h1></div>
    <div class="card empty">
        <x-icon name="tool" />
        <h2>Module en préparation</h2>
        <p>Cette partie arrive à la phase {{ $phase }} du développement.</p>
    </div>
@endsection
