@extends('layouts.base')

@section('body')
    <main class="auth">
        <div class="auth-card">
            <div class="auth-brand">
                <x-brand-logo variant="full" />
                {{-- Le logo contient déjà le nom : le titre reste lu par les lecteurs d'écran. --}}
                <h1 @class(['visually-hidden' => $settings->get('branding.logo_path') || is_file(public_path(config('entreprise.default_images.logo')))])>{{ $settings->get('company.trade_name') }}</h1>
                @if ($settings->get('company.slogan'))
                    <p class="slogan">{{ $settings->get('company.slogan') }}</p>
                @endif
            </div>
            <div class="card">
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif
                @yield('content')
            </div>
        </div>
    </main>
@endsection
