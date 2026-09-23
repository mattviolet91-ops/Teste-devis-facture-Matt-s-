@extends('layouts.guest', ['title' => 'Mot de passe oublié'])

@section('content')
    <h2>Mot de passe oublié</h2>
    <p class="muted small">Indiquez votre email : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
    <form method="POST" action="{{ route('password.email') }}" class="form-grid">
        @csrf
        <x-field name="email" label="Email" type="email" autocomplete="username" required autofocus />
        <button class="btn btn-block" type="submit">Envoyer le lien</button>
        <p class="small" style="text-align:center;margin:0"><a href="{{ route('login') }}">Retour à la connexion</a></p>
    </form>
@endsection
