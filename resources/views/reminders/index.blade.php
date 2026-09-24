@extends('layouts.app', ['title' => 'Relances'])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Relances</h1>
            <p>Factures en retard ou qui arrivent à échéance dans les 3 jours.</p>
        </div>
    </div>

    @if ($invoices->isEmpty())
        <div class="card empty"><x-icon name="check" /><h2>Aucune relance à faire</h2></div>
    @else
        <ul class="list">
            @foreach ($invoices as $invoice)
                @php $late = (int) $invoice->due_date->diffInDays(today(), false); @endphp
                <li>
                    <a class="list-item" href="{{ route('reminders.show', $invoice) }}">
                        <span class="list-main">
                            <strong>{{ $invoice->client?->displayName() }}</strong>
                            <span class="muted small">{{ $invoice->number }} · échéance {{ $invoice->due_date->format('d/m/Y') }}{{ $invoice->reminder_count ? ' · relancé '.$invoice->reminder_count.' fois' : '' }}</span>
                        </span>
                        <span class="list-meta">
                            <strong class="amount">{{ Money::format($invoice->balance()) }}</strong>
                            <span class="badge {{ $late > 0 ? 'badge-danger' : 'badge-warning' }}">{{ $late > 0 ? 'retard '.$late.' j' : ($late === 0 ? 'aujourd\'hui' : 'dans '.(-$late).' j') }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
