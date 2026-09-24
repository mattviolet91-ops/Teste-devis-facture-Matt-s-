@extends('layouts.portal', ['title' => 'Paiement '.$invoice->number])

@php use App\Support\Money; @endphp

@section('content')
    <div class="card portal-pay">
        <h1>Paiement sécurisé</h1>
        @if ($test)<div class="alert alert-warning">Mode TEST : aucun argent réel ne sera débité.</div>@endif
        <p>{{ $invoice->kindLabel() }} {{ $invoice->number }} — <strong>{{ Money::format($invoice->balance()) }}</strong></p>
        <p class="muted small">Vous allez être redirigé vers la page de paiement sécurisée de notre prestataire myPOS.</p>
        <form method="POST" action="{{ $form['action'] }}" data-autosubmit>
            @foreach ($form['fields'] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <button class="btn" type="submit"><x-icon name="wallet" /> Continuer vers le paiement</button>
        </form>
    </div>
    <script src="{{ asset('js/autosubmit.js') }}?v={{ filemtime(public_path('js/autosubmit.js')) }}" defer></script>
@endsection
