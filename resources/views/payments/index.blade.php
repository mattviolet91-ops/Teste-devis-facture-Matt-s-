@extends('layouts.app', ['title' => 'Paiements'])

@php use App\Support\Money; @endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Paiements</h1>
            <p>Encaissé du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }} : <strong>{{ Money::format($total) }}</strong></p>
        </div>
        <a class="btn" href="{{ route('payments.requests.create') }}"><x-icon name="plus" /> Encaisser un montant</a>
    </div>

    @if ($freeLink)
        @php $freeMessage = "Bonjour,\n\nVous pouvez régler par carte bancaire, en toute sécurité, avec ce lien :\n{$freeLink}\n\nMerci,\n".$settings->get('company.trade_name'); @endphp
        <details class="card" data-share>
            <summary><strong>Lien de paiement à montant libre</strong> <span class="muted small">— le client indique lui-même le montant</span></summary>
            <p class="small muted" style="margin-top:.75rem">Un seul lien, toujours le même, à donner à n'importe quel client : il indique son nom, le motif et le montant, puis paie par carte.
                Vous êtes prévenu, sa fiche est créée si besoin, il reçoit un reçu, et l'encaissement arrive dans « À rattacher » ci-dessous. Pour un montant fixé par vous, préférez « Encaisser un montant ».
                Montants acceptés et désactivation : <a href="{{ route('settings.payments') }}#lien-libre">Réglages → Paiement en ligne</a>.</p>
            <div class="copy-field">
                <input type="text" value="{{ $freeLink }}" readonly aria-label="Lien de paiement à montant libre" data-copy-source>
                <button class="btn btn-secondary btn-sm" type="button" data-copy>Copier</button>
            </div>
            <textarea rows="5" aria-label="Message au client" data-share-message style="margin-top:.5rem;width:100%">{{ $freeMessage }}</textarea>
            <div class="chips chips-wrap" style="margin-top:.5rem">
                <a class="chip" href="#" data-share-to="whatsapp" data-phone=""><x-icon name="message" /> WhatsApp</a>
                <a class="chip" href="#" data-share-to="sms" data-phone=""><x-icon name="message" /> SMS</a>
                <button class="chip" type="button" data-share-to="copy"><x-icon name="copy" /> Copier le message</button>
            </div>
        </details>
    @endif

    @if ($unallocated->isNotEmpty())
        <div class="card" id="a-rattacher">
            <div class="card-head"><h2>À rattacher à une facture</h2><span class="muted small">{{ $unallocated->count() }} encaissement(s) · {{ Money::format((int) $unallocated->sum('amount')) }}</span></div>
            <p class="small muted" style="margin-top:0">Montants encaissés sans facture. Choisissez une facture envoyée et pas encore soldée du client : le paiement s'y enregistre.</p>
            <ul class="attachment-list">
                @foreach ($unallocated as $payment)
                    @php $candidates = $attachableInvoices[$payment->client_id] ?? collect(); @endphp
                    <li>
                        <div><strong>{{ Money::format($payment->amount) }}</strong> · <a href="{{ route('clients.show', $payment->client_id) }}">{{ $payment->client?->displayName() }}</a></div>
                        <span class="muted small">{{ $payment->label ?: 'Sans libellé' }} · {{ $payment->paid_at->format('d/m/Y') }} · {{ $payment->methodLabel() }}{{ $payment->reference ? ' · réf. '.$payment->reference : '' }}
                            <form method="POST" action="{{ route('payments.destroy', $payment) }}" data-confirm="Supprimer cet encaissement de {{ Money::plain($payment->amount) }} ? À ne faire que s'il a été saisi par erreur." style="display:inline">
                                @csrf
                                @method('DELETE')
                                <button class="link-danger" type="submit">Supprimer</button>
                            </form>
                        </span>
                        @error('attach_'.$payment->id)<div class="alert alert-error" style="margin:.5rem 0 0">{{ $message }}</div>@enderror
                        @if ($candidates->isEmpty())
                            <p class="small" style="margin:.375rem 0 0">Aucune facture envoyée à régler pour ce client.
                                <a href="{{ route('invoices.create', ['client' => $payment->client_id]) }}">Créer une facture</a>, envoyez-la, puis revenez la choisir ici.</p>
                        @else
                            <form method="POST" action="{{ route('payments.attach', $payment) }}" class="copy-field" style="margin-top:.5rem">
                                @csrf
                                <select name="invoice_id" required aria-label="Facture à laquelle rattacher {{ Money::plain($payment->amount) }}">
                                    @if ($candidates->count() > 1)<option value="">Choisir la facture…</option>@endif
                                    @foreach ($candidates as $candidate)
                                        <option value="{{ $candidate->id }}" @disabled($candidate->balance() < $payment->amount)>{{ $candidate->kindLabel() }} {{ $candidate->number }} — reste {{ Money::format($candidate->balance()) }}{{ $candidate->balance() < $payment->amount ? ' (trop petit)' : '' }}</option>
                                    @endforeach
                                </select>
                                <button class="btn btn-sm" type="submit">Rattacher</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($pendingRequests->isNotEmpty())
        <div class="card">
            <h2>Liens de paiement en attente</h2>
            <ul class="stat-list">
                @foreach ($pendingRequests as $pending)
                    <li><span><a href="{{ route('payments.requests.show', $pending) }}">{{ $pending->label }}</a> <span class="muted small">· {{ $pending->client?->displayName() }} · {{ $pending->created_at->format('d/m/Y') }}</span></span><strong>{{ Money::format($pending->amount) }}</strong></li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('payments.index') }}" class="card filters">
        <div class="form-grid cols-2">
            <div class="field"><label for="du">Du</label><input id="du" type="date" name="du" value="{{ $from->toDateString() }}"></div>
            <div class="field"><label for="au">Au</label><input id="au" type="date" name="au" value="{{ $to->toDateString() }}"></div>
            <div class="field">
                <label for="mode">Moyen de paiement</label>
                <select id="mode" name="mode">
                    <option value="">Tous</option>
                    @foreach (\App\Models\Payment::METHODS as $key => $label)
                        <option value="{{ $key }}" @selected($mode === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="align-self:end"><button class="btn btn-secondary" type="submit">Afficher</button></div>
        </div>
        <div class="chips" style="margin-top:.5rem">
            <a class="chip" href="{{ route('payments.index', ['du' => today()->startOfMonth()->toDateString(), 'au' => today()->toDateString()]) }}">Ce mois</a>
            <a class="chip" href="{{ route('payments.index', ['du' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'au' => today()->subMonthNoOverflow()->endOfMonth()->toDateString()]) }}">Mois dernier</a>
            <a class="chip" href="{{ route('payments.index', ['du' => today()->startOfYear()->toDateString(), 'au' => today()->toDateString()]) }}">Cette année</a>
        </div>
    </form>

    @if ($byMethod->isNotEmpty())
        <div class="card">
            <h2>Par moyen de paiement</h2>
            <ul class="stat-list">
                @foreach ($byMethod as $row)
                    <li><span>{{ \App\Models\Payment::METHODS[$row->method] ?? $row->method }} <span class="muted small">({{ $row->count }})</span></span><strong>{{ Money::format((int) $row->total) }}</strong></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($openInvoices->isNotEmpty())
        <div class="card">
            <h2>Encaisser une facture</h2>
            <ul class="stat-list">
                @foreach ($openInvoices as $invoice)
                    <li>
                        <a href="{{ route('invoices.show', ['invoice' => $invoice, 'encaisser' => 1]) }}#paiements">{{ $invoice->number }} · {{ $invoice->client?->displayName() }}</a>
                        <span>@include('invoices._status') <strong>{{ Money::format($invoice->balance()) }}</strong></span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($payments->isEmpty())
        <div class="card empty"><x-icon name="wallet" /><h2>Aucun paiement sur cette période</h2>
            <p><a class="btn" href="{{ route('invoices.index', ['status' => 'unpaid']) }}"><x-icon name="receipt" /> Factures à encaisser</a></p></div>
    @else
        <ul class="list">
            @foreach ($payments as $payment)
                <li>
                    <a class="list-item" href="{{ $payment->invoice ? route('invoices.show', $payment->invoice).'#paiements' : route('payments.index').'#a-rattacher' }}">
                        <span class="list-main">
                            <strong>{{ $payment->client?->displayName() }}</strong>
                            <span class="muted small">{{ $payment->paid_at->format('d/m/Y') }} · {{ $payment->methodLabel() }} · {{ $payment->invoice ? 'facture '.$payment->invoice->number : 'à rattacher à une facture' }}</span>
                        </span>
                        <span class="list-meta"><strong class="amount">{{ Money::format($payment->amount) }}</strong></span>
                    </a>
                </li>
            @endforeach
        </ul>
        {{ $payments->links('components.pagination') }}
    @endif
@endsection
