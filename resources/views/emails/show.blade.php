@extends('layouts.app', ['title' => $email->subject])

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $email->subject }}</h1>
            <p>
                <span class="badge {{ $email->isSent() ? 'badge-success' : 'badge-danger' }}">{{ $email->isSent() ? 'Envoyé' : 'Échec' }}</span>
                {{ $email->created_at->format('d/m/Y à H:i') }}
            </p>
        </div>
    </div>

    @if ($email->error)<div class="alert alert-error">{{ $email->error }}</div>@endif

    <div class="card">
        <dl class="details">
            <dt>À</dt><dd>{{ $email->to }}</dd>
            @if ($email->cc)<dt>Copie</dt><dd>{{ $email->cc }}</dd>@endif
            @if ($email->client)<dt>Client</dt><dd><a href="{{ route('clients.show', $email->client) }}">{{ $email->client->displayName() }}</a></dd>@endif
            @if ($email->document instanceof \App\Models\Quote)<dt>Devis</dt><dd><a href="{{ route('quotes.show', $email->document) }}">{{ $email->document->displayNumber() }}</a></dd>@endif
            @if ($email->document instanceof \App\Models\Invoice)<dt>Document</dt><dd><a href="{{ route('invoices.show', $email->document) }}">{{ $email->document->kindLabel() }} {{ $email->document->displayNumber() }}</a></dd>@endif
            @if ($email->attachment)<dt>Pièce jointe</dt><dd>{{ $email->attachment }}</dd>@endif
        </dl>
    </div>

    <div class="card">
        <h2>Message</h2>
        <p class="pre-line" style="margin:0">{{ $email->body }}</p>
    </div>
@endsection
