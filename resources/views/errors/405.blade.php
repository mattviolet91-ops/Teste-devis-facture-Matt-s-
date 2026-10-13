@extends('errors.layout')

@section('code', '405')
@section('title', 'Page à rouvrir')
@section('message', "Ce formulaire n'a pas pu être pris en compte depuis cette page. Revenez en arrière, rechargez la page puis recommencez.")
@section('actions')
    <a class="btn btn-secondary" href="{{ url()->previous() }}">Revenir en arrière</a>
@endsection
