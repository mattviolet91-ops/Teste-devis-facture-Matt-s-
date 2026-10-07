{{-- Devis et factures d'un chantier (liens). $current : document de la page, non répété. --}}
@php
    $docs = collect($job['quotes'])->map(fn ($q) => ['label' => 'devis '.$q->number, 'url' => route('quotes.show', $q), 'id' => 'q'.$q->id])
        ->concat(collect($job['invoices'])->map(fn ($i) => ['label' => mb_strtolower($i->kindLabel()).' '.$i->number, 'url' => route('invoices.show', $i).'#frais', 'id' => 'i'.$i->id]))
        ->reject(fn ($d) => $d['id'] === ($current ?? null))->values();
@endphp
<p class="small" style="margin-top:0">
    Chantier <a href="{{ $job['url'] }}"><strong>« {{ $job['title'] }} »</strong></a>@if ($docs->isNotEmpty()) : mêmes frais sur
        @foreach ($docs as $doc)<a href="{{ $doc['url'] }}">{{ $doc['label'] }}</a>{{ $loop->last ? '.' : ', ' }}@endforeach
    @else.@endif
</p>
