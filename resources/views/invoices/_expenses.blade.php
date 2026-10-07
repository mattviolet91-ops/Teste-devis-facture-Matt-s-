{{-- Frais du chantier de cette facture : visibles par le gérant seul (jamais sur le PDF ni le lien client). --}}
@php $job = app(\App\Services\JobCostService::class)->forInvoice($invoice); @endphp
<div class="card" id="frais">
    <div class="card-head">
        <h2>Frais du chantier</h2>
        <span class="badge">Visible par vous seul</span>
    </div>
    @php $others = $job['invoices']->reject(fn ($i) => $i->id === $invoice->id); @endphp
    @if ($job['quote'])
        <p class="small" style="margin-top:0">
            Mêmes frais sur tout le chantier du <a href="{{ route('quotes.show', $job['quote']) }}">devis {{ $job['quote']->number }}</a>{{ $others->isNotEmpty() ? ', donc aussi sur ' : '.' }}
            @foreach ($others as $other)<a href="{{ route('invoices.show', $other) }}#frais">{{ mb_strtolower($other->kindLabel()) }} {{ $other->number }}</a>{{ $loop->last ? '.' : ', ' }}@endforeach
        </p>
    @endif
    @include('expenses._panel', ['job' => $job, 'action' => route('expenses.store', $invoice)])
    @if (in_array($invoice->status, \App\Models\Invoice::ISSUED, true))
        <p class="small" style="margin:.75rem 0 0"><a href="{{ $job['url'] }}">Page des frais de ce chantier</a> · <a href="{{ route('expenses.index') }}">Tous les chantiers</a></p>
    @endif
</div>
