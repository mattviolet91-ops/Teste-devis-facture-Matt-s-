@extends('layouts.app', ['title' => 'Frais généraux'])

@php
    use App\Support\Money;
    $assujetti = app(\App\Services\Settings::class)->get('vat.regime') === 'assujetti';
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Frais généraux</h1>
            <p>Les dépenses qui ne concernent pas un chantier précis : outillage, carburant, assurance du véhicule… Visible par vous seul.</p>
        </div>
    </div>

    <div class="card">
        <ul class="stat-list">
            <li class="remaining"><span>Total des frais généraux{{ $assujetti ? ' HT' : '' }}</span><strong>{{ Money::format($general['expenses_total']) }}</strong></li>
        </ul>
        @include('expenses._list', ['expenses' => $general['expenses'], 'empty' => 'Aucun frais général pour le moment.'])
        <details id="ajouter" @if ($general['expenses']->isEmpty() || $errors->hasAny(['expense_label', 'expense_amount', 'expense_vat', 'expense_date', 'receipt'])) open @endif>
            <summary>Ajouter un frais général</summary>
            <form method="POST" action="{{ route('expenses.general.store') }}" enctype="multipart/form-data" class="form-grid cols-2" style="margin-top:.75rem">
                @csrf
                @include('expenses._fields', ['assujetti' => $assujetti])
                <div class="span-2"><button class="btn" type="submit"><x-icon name="plus" /> Ajouter</button></div>
            </form>
        </details>
    </div>
@endsection
