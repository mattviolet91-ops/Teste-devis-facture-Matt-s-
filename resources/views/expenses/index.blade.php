@extends('layouts.app', ['title' => 'Achats et marges'])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Achats et marges</h1>
            <p>Achats du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }} : <strong>{{ Money::format($total) }}</strong>@if ($total !== $totalTtc) HT ({{ Money::format($totalTtc) }} TTC)@endif</p>
        </div>
        <div class="action-bar" style="margin:0">
            <a class="btn" href="{{ route('expenses.create') }}"><x-icon name="plus" /> Achat</a>
        </div>
    </div>

    <div class="period-bar">
        <div class="chips" role="group" aria-label="Période">
            @foreach (\App\Http\Controllers\ExpenseController::PERIODS as $key => $label)
                <a class="chip {{ $period === $key ? 'is-active' : '' }}" href="{{ route('expenses.index', ['periode' => $key]) }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    @if ($chantiers->isNotEmpty())
        <div class="card">
            <div class="card-head"><h2>Marge par chantier</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Chantier</th><th class="num">CA HT</th><th class="num">Achats</th><th class="num">Marge</th></tr></thead>
                    <tbody>
                        @foreach ($chantiers as $m)
                            <tr>
                                <td><a href="{{ route('quotes.show', $m['quote']) }}#marge">{{ $m['quote']->client?->displayName() }}</a><br><span class="small muted">{{ $m['quote']->number }} · {{ $m['basis'] }}</span></td>
                                <td class="num">{{ Money::format($m['revenue']) }}</td>
                                <td class="num">{{ Money::format($m['costs']) }}</td>
                                <td class="num"><strong class="{{ $m['margin'] < 0 ? 'text-danger' : '' }}">{{ Money::format($m['margin']) }}</strong>@if ($m['rate'] !== null)<br><span class="small muted">{{ $m['rate'] }} %</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted small" style="margin:.75rem 0 0">Chantiers acceptés sur la période ou ayant des achats sur la période. CA « prévu au devis » tant que rien n'est facturé.</p>
        </div>
    @endif

    @if ($byCategory->count() > 1)
        <div class="card">
            <h2>Par catégorie</h2>
            <ul class="stat-list">
                @foreach ($byCategory as $category => $amount)
                    <li><span>{{ \App\Models\Expense::CATEGORIES[$category] ?? $category }}</span><strong>{{ Money::format($amount) }}</strong></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($expenses->isEmpty())
        <div class="card empty">
            <x-icon name="receipt" />
            <h2>Aucun achat sur cette période</h2>
            <p class="muted">Prenez en photo vos tickets de matériaux et rattachez-les au chantier : la marge se calcule toute seule.</p>
            <a class="btn" href="{{ route('expenses.create') }}"><x-icon name="plus" /> Ajouter un achat</a>
        </div>
    @else
        <ul class="list">
            @foreach ($expenses as $expense)
                <li>
                    <a class="list-item" href="{{ route('expenses.edit', $expense) }}">
                        <span class="list-main">
                            <strong>{{ $expense->label }}</strong>
                            <span class="muted small">{{ $expense->spent_on->format('d/m/Y') }} · {{ $expense->categoryLabel() }}{{ $expense->supplier ? ' · '.$expense->supplier : '' }}{{ $expense->receipt_path ? ' · ticket joint' : '' }}</span>
                            <span class="muted small">{{ $expense->quote ? 'Chantier '.$expense->quote->number.' · '.$expense->quote->client?->displayName() : 'Frais général' }}</span>
                        </span>
                        <span class="list-meta"><strong class="amount">{{ Money::format($expense->amount_ttc) }}</strong></span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
