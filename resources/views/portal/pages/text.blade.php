@extends('layouts.portal', ['title' => $title.' — '.$settings->get('company.trade_name')])

@php $company = $settings->group('company'); $text = trim((string) $settings->get($setting)); @endphp

@section('content')
    <article class="card legal-text">
        <h1>{{ $title }}</h1>
        <p class="muted small">{{ $company['trade_name'] }} ({{ $company['legal_form'] }}) — SIRET {{ $company['siret'] }}</p>
        @forelse (preg_split('/\R+/', $text) as $line)
            @if (preg_match('/^(\d+)\.\s+([^.]{2,90})\.\s*(.*)$/u', trim($line), $m))
                <h2>{{ $m[1] }}. {{ $m[2] }}</h2>
                <p>{{ $m[3] }}</p>
            @elseif (trim($line) !== '')
                <p>{{ trim($line) }}</p>
            @endif
        @empty
            <p>Document en cours de rédaction.</p>
        @endforelse
        <h2>Contact</h2>
        <p>{{ $company['trade_name'] }} — {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }} — {{ $company['phone'] }} — {{ $company['email'] }}</p>
        @if (! empty($company['mediator_name']))
            <p>Médiateur de la consommation : {{ $company['mediator_name'] }}@if (! empty($company['mediator_url'])) — <a href="{{ $company['mediator_url'] }}">{{ preg_replace('#^https?://#', '', $company['mediator_url']) }}</a>@endif</p>
        @endif
    </article>
@endsection
