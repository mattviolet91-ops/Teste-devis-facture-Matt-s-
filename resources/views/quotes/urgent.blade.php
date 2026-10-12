@extends('layouts.portal', ['title' => 'Intervention urgente'])

@php use App\Support\Money; @endphp

{{--
    Le client confirme avoir demandé une intervention urgente (devis déjà signé chez lui).
    Sert sur le téléphone de l'artisan et depuis le lien envoyé au client. Attend : $quote, $action, $back.
--}}
@section('content')
    <p><a class="small" href="{{ $back }}">← Retour au devis</a></p>

    <div class="page-head">
        <div>
            <h1>Intervention urgente</h1>
            <p>Devis {{ $quote->number }}{{ $quote->title ? ' — '.$quote->title : '' }} — {{ Money::format($quote->total_ttc) }}, signé le {{ $quote->signed_at->format('d/m/Y') }}</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert"><ul class="error-list">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ $action }}" class="card" data-signature-form>
        @csrf
        <p class="small">À remplir seulement si vous avez vous-même demandé à {{ $settings->get('company.trade_name') }} d'intervenir en urgence à votre domicile (fuite, tuiles envolées, dégâts après une tempête…).</p>
        <div class="field">
            <label for="reason">Nature de l'urgence *</label>
            <textarea id="reason" name="reason" rows="2" required maxlength="500" placeholder="ex. Fuite d'eau dans la chambre après l'orage">{{ old('reason') }}</textarea>
        </div>
        <div class="field">
            <label for="name">Nom et prénom *</label>
            <input id="name" type="text" name="name" value="{{ old('name', $quote->signed_name ?: $quote->client?->contactName()) }}" required maxlength="160" autocomplete="off">
        </div>
        <div class="field">
            <label>Signature *</label>
            <div class="signature-pad"><canvas data-signature-canvas aria-label="Zone de signature"></canvas></div>
            <button type="button" class="btn btn-secondary btn-sm" data-signature-clear>Effacer</button>
            <input type="hidden" name="signature" data-signature-input>
        </div>
        <label class="check"><input type="checkbox" name="agree" value="1" required>
            <span>Je confirme avoir expressément demandé une intervention urgente à mon domicile. Pour les travaux strictement nécessaires à cette urgence, le paiement peut être demandé sans attendre 7 jours et je ne dispose pas du droit de rétractation (articles L221-10 et L221-28 du Code de la consommation).</span></label>
        <div class="form-actions"><button class="btn" type="submit"><x-icon name="check" /> Je confirme</button></div>
    </form>

    <script src="{{ asset('js/signature.js') }}?v={{ filemtime(public_path('js/signature.js')) }}" defer></script>
@endsection
