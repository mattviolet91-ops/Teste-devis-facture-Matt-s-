{{-- Frais du chantier de cette facture : visibles par le gérant seul (jamais sur le PDF ni le lien client). --}}
@php $job = app(\App\Services\JobCostService::class)->forInvoice($invoice); @endphp
<div class="card" id="frais">
    <div class="card-head">
        <h2>Frais du chantier</h2>
        <span class="badge">Visible par vous seul</span>
    </div>
    @if ($job['quote'] && $job['invoices']->count() > 1)
        <p class="muted small">Toutes les factures de ce chantier comptent : {{ $job['numbers'] }}.</p>
    @endif
    @include('expenses._panel', ['job' => $job, 'action' => route('expenses.store', $invoice)])
    @if (in_array($invoice->status, \App\Models\Invoice::ISSUED, true))
        <p class="small" style="margin:.75rem 0 0"><a href="{{ $job['url'] }}">Page des frais de ce chantier</a> · <a href="{{ route('expenses.index') }}">Tous les chantiers</a></p>
    @endif
</div>
