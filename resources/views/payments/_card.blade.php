{{-- Paiements d'une facture et formulaire d'encaissement. Attend : $invoice. --}}
@php use App\Support\Money; @endphp
@if (! $invoice->isCredit() && ! $invoice->isDraft())
    <div class="card" id="paiements">
        <div class="card-head">
            <h2>Paiements</h2>
            <span class="muted small">Réglé {{ Money::format($invoice->amount_paid) }} sur {{ Money::format($invoice->total_ttc) }}</span>
        </div>
        @php $percent = $invoice->total_ttc > 0 ? min(100, (int) round($invoice->amount_paid * 100 / $invoice->total_ttc)) : 0; @endphp
        <div class="progress" role="progressbar" aria-label="Part de la facture déjà réglée" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $percent }}%"></span></div>

        {{-- Montants libres déjà encaissés auprès de ce client, sans facture : à rattacher ici. --}}
        @if ($invoice->acceptsPayments())
            @foreach (\App\Models\Payment::query()->unallocated()->where('client_id', $invoice->client_id)->oldest('paid_at')->get() as $waiting)
                <div class="alert alert-info" style="margin-top:.75rem">
                    @error('attach_'.$waiting->id)<div class="alert alert-error">{{ $message }}</div>@enderror
                    <strong>{{ Money::format($waiting->amount) }}</strong> déjà encaissé le {{ $waiting->paid_at->format('d/m/Y') }} ({{ $waiting->methodLabel() }}{{ $waiting->label ? ' — '.$waiting->label : '' }}), sans facture.
                    @if ($waiting->amount <= $invoice->balance())
                        <form method="POST" action="{{ route('payments.attach', $waiting) }}" style="margin-top:.5rem">
                            @csrf
                            <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">
                            <button class="btn btn-sm" type="submit">Rattacher à cette facture</button>
                        </form>
                    @else
                        <br><span class="small">Il dépasse le reste à payer de cette facture ({{ Money::format($invoice->balance()) }}).</span>
                    @endif
                </div>
            @endforeach
        @endif

        @if ($invoice->payments->isNotEmpty())
            <ul class="stat-list">
                @foreach ($invoice->payments as $payment)
                    <li>
                        <span>
                            <strong>{{ Money::format($payment->amount) }}</strong> — {{ $payment->methodLabel() }}
                            <span class="muted small">le {{ $payment->paid_at->format('d/m/Y') }}{{ $payment->reference ? ' · réf. '.$payment->reference : '' }}</span>
                            @if ($payment->notes)<br><span class="small muted">{{ $payment->notes }}</span>@endif
                        </span>
                        <form method="POST" action="{{ route('payments.destroy', $payment) }}" data-confirm="Supprimer ce paiement de {{ Money::format($payment->amount) }} ?">
                            @csrf
                            @method('DELETE')
                            <button class="link-danger" type="submit">Supprimer</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($invoice->acceptsPayments())
            @error('amount')<div class="alert alert-error">{{ $message }}</div>@enderror
            @if ($waitUntil = $invoice->paymentWaitUntil())
                <div class="alert alert-info small">Devis signé chez le client le {{ $invoice->quote->signed_at->format('d/m/Y') }} : la facture peut être envoyée dès maintenant, mais la loi interdit de recevoir un paiement avant le <strong>{{ $waitUntil->format('d/m/Y') }}</strong> (art. L221-10 du Code de la consommation).{{ $invoice->legalPaymentFrom() ? ' L\'échéance de la facture est fixée à cette date.' : '' }} Le paiement par carte n'est proposé au client et les relances automatiques ne partent qu'à partir de là. Exception : intervention urgente demandée par le client (<a href="{{ route('quotes.show', $invoice->quote) }}#urgence">le client peut la confirmer depuis le devis</a>).</div>
            @endif
            <details @if ($errors->hasAny(['amount', 'paid_at', 'method', 'method_detail']) || request('encaisser')) open @endif>
                <summary class="btn"><x-icon name="wallet" /> Encaisser un paiement</summary>
                <form method="POST" action="{{ route('payments.store', $invoice) }}" style="margin-top:1rem" data-offline="Paiement">
                    @csrf
                    <div class="form-grid cols-2">
                        <x-field name="amount" label="Montant (€)" :value="\App\Support\LineInput::money($invoice->balance())" inputmode="decimal" required />
                        <x-field name="paid_at" label="Date" type="date" :value="today()->toDateString()" required />
                        <div class="field span-2">
                            <label>Moyen de paiement</label>
                            <div class="chips chips-wrap" role="radiogroup">
                                @foreach (\App\Models\Payment::METHODS as $key => $label)
                                    <label class="chip chip-radio"><input type="radio" name="method" value="{{ $key }}" @checked(old('method', 'virement') === $key)> {{ $label }}</label>
                                @endforeach
                            </div>
                        </div>
                        <x-field name="method_detail" label="Précision (si « Autre »)" placeholder="ex. PayPal, Lydia…" />
                        <x-field name="reference" label="Référence (n° de chèque…)" />
                        <x-field name="notes" label="Note (facultatif)" class="span-2" />
                    </div>
                    <div class="form-actions"><button class="btn" type="submit">Enregistrer le paiement</button></div>
                </form>
            </details>
            @if (app(\App\Services\MyposGateway::class)->isEnabled())
                <p class="small muted" style="margin-top:.75rem">💳 Paiement par carte myPOS activé : le client {{ $waitUntil ? 'pourra payer' : 'peut payer' }} {{ Money::format($invoice->balance()) }} depuis sa facture en ligne{{ $waitUntil ? ' à partir du '.$waitUntil->format('d/m/Y') : '' }}, le paiement s'enregistre tout seul.</p>
            @elseif (auth()->user()->canOpen('settings.payments'))
                <p class="small muted" style="margin-top:.75rem">Pour que le client puisse payer par carte depuis sa facture en ligne, activez myPOS dans <a href="{{ route('settings.payments') }}">Réglages → Paiement en ligne</a>.</p>
            @endif
        @endif
    </div>
@endif
