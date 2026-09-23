@extends('layouts.base')

{{-- Espace client : page publique aux couleurs de l'entreprise, sans menu de gestion. --}}
@section('body')
    @php $company = $settings->group('company'); @endphp
    <header class="portal-head">
        <div class="portal-wrap">
            <x-brand-logo variant="full" />
            <a class="portal-call" href="tel:{{ preg_replace('/\s+/', '', $company['phone']) }}"><x-icon name="phone" /> {{ $company['phone'] }}</a>
        </div>
    </header>
    <main class="portal-wrap portal-main">
        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif
        @yield('content')
    </main>
    <footer class="portal-wrap portal-foot small muted">
        {{ $company['trade_name'] }} ({{ $company['legal_form'] }}) — {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }}<br>
        {{ $company['phone'] }} · {{ $company['email'] }}@if (! empty($company['website'])) · <a href="{{ $company['website'] }}">{{ preg_replace('#^https?://#', '', $company['website']) }}</a>@endif
    </footer>
@endsection
