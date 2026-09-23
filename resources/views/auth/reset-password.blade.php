@extends('layouts.guest', ['title' => 'Nouveau mot de passe'])

@section('content')
    <h2>Nouveau mot de passe</h2>
    <form method="POST" action="{{ route('password.update') }}" class="form-grid">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field name="email" label="Email" type="email" :value="$email" autocomplete="username" required />
        <x-field name="password" label="Nouveau mot de passe" type="password" autocomplete="new-password" hint="12 caractères minimum, avec des lettres et des chiffres." required />
        <x-field name="password_confirmation" label="Confirmation" type="password" autocomplete="new-password" required />
        <button class="btn btn-block" type="submit">Enregistrer</button>
    </form>
@endsection
