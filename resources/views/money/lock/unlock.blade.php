@extends('layouts.app', ['title' => 'Espace Argent'])

@section('content')
    <div class="lock-screen">
        <x-icon name="lock" class="icon lock-icon" />
        <h1>Espace Argent verrouillé</h1>
        <p class="muted">Tapez votre code Argent.</p>
        <form method="POST" action="{{ route('money.unlock.store') }}" class="card">
            @csrf
            <div class="field @error('code') has-error @enderror">
                <label for="code" class="visually-hidden">Code Argent</label>
                <input id="code" class="pin-input" type="password" name="code" inputmode="numeric" maxlength="8" autocomplete="off" required autofocus @disabled($blockedFor > 0)>
                @error('code')<span class="error">{{ $message }}</span>@enderror
                @if ($blockedFor > 0 && ! $errors->has('code'))<span class="error">Trop de codes faux. Réessayez dans {{ max(1, (int) ceil($blockedFor / 60)) }} minute(s).</span>@endif
            </div>
            <div class="form-actions"><button class="btn btn-block" type="submit" @disabled($blockedFor > 0)>Ouvrir</button></div>
        </form>
        <p class="small"><a href="{{ route('money.forgot') }}">Code oublié ?</a></p>
    </div>
@endsection
