@extends('layouts.guest', ['title' => 'Connexion'])

@section('content')
    <form method="POST" action="{{ route('login') }}" class="form-grid">
        @csrf
        <x-field name="email" label="Email" type="email" autocomplete="username" required autofocus />
        <x-field name="password" label="Mot de passe" type="password" autocomplete="current-password" required />
        <label class="check">
            <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
            <span>Rester connecté sur cet appareil</span>
        </label>
        <button class="btn btn-block" type="submit">Se connecter</button>
        <p class="small" style="text-align:center;margin:0"><a href="{{ route('password.request') }}">Mot de passe oublié ?</a></p>
    </form>
@endsection
