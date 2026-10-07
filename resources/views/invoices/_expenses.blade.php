{{-- Frais du chantier de cette facture : visibles par le gérant seul (jamais sur le PDF ni le lien client). --}}
@php $job = app(\App\Services\JobCostService::class)->forInvoice($invoice); @endphp
<div class="card" id="frais">
    <div class="card-head">
        <h2>Frais du chantier</h2>
        <span class="badge">Visible par vous seul</span>
    </div>
    @include('expenses._documents', ['job' => $job, 'current' => 'i'.$invoice->id])
    @if ($invoice->quote_id && $invoice->quote)
        @include('expenses._attach', ['job' => $job, 'clientId' => $invoice->client_id, 'what' => 'le devis '.$invoice->quote->number.' (et ses factures)', 'action' => route('expenses.attach.quote', $invoice->quote)])
    @else
        @include('expenses._attach', ['job' => $job, 'clientId' => $invoice->client_id, 'what' => 'cette facture', 'action' => route('expenses.attach.invoice', $invoice)])
    @endif
    @include('expenses._panel', ['job' => $job, 'action' => route('expenses.store', $invoice)])
    <p class="small" style="margin:.75rem 0 0"><a href="{{ route('expenses.index') }}">Tous les chantiers</a></p>
</div>
