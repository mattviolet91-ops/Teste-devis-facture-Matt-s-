@extends('layouts.guest', ['title' => 'Hors connexion'])

@section('content')
    <h2>Pas de réseau</h2>
    <p>Cette page n'a pas encore été enregistrée sur le téléphone.</p>
    <p class="small muted">Les pages déjà ouvertes restent consultables sans réseau. Pour tout garder sur le téléphone, utilisez
        « Télécharger pour le hors connexion » dans Réglages → Mon compte quand vous avez du réseau.</p>
    <p><a class="btn" href="/">Réessayer</a></p>
@endsection
