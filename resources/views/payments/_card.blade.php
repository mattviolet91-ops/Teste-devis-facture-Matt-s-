{{-- Paiements d'une facture et formulaire d'encaissement. Attend : $invoice. --}}
@php use App\Support\Money; @endphp
@if (! $invoice->isCredit() && ! $invoice->isDraft())
    <div class="card" id="paiements">
        <div class="card-head">
            <h2>Paiements</h2>
            <span class="muted small">Réglé {{ Money::format($invoice->amount_paid) }} sur {{ Money::format($invoice->total_ttc) }}</span>
        </div>
        @php $percent = $invoice->total_ttc > 0 ? min(100, (int) round($invoice->amount_paid * 100 / $invoice->total_ttc)) : 0; @endphp
        <div class="progress" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $percent }}%"></span></div>

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
            @php
                $quote = $invoice->quote;
                $recent = $quote?->signed_at && $quote->signed_at->gt(now()->subDays(7)) && $invoice->client?->isIndividual();
            @endphp
            @if ($recent)
                <div class="alert alert-info small">Rappel : devis signé le {{ $quote->signed_at->format('d/m/Y') }}. Pour un contrat conclu chez un particulier, aucun paiement ne peut être exigé avant 7 jours.</div>
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
                            <div class="chips" role="radiogroup">
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
                <p class="small muted" style="margin-top:.75rem">💳 Paiement par carte myPOS activé : le client peut payer {{ Money::format($invoice->balance()) }} depuis sa facture en ligne, le paiement s'enregistre tout seul.</p>
            @else
            <details style="margin-top:.75rem" @if ($errors->has('payment_link')) open @endif>
                <summary class="small">Paiement par carte en ligne{{ $invoice->cardPaymentUrl() ? ' : proposé au client' : '' }}</summary>
                <form method="POST" action="{{ route('invoices.payment-link', $invoice) }}" style="margin-top:.5rem">
                    @csrf
                    @method('PUT')
                    <x-field name="payment_link" label="Lien de paiement pour cette facture (montant fixe)" :value="$invoice->payment_link" placeholder="https://…"
                        :hint="$settings->get('bank.card_link') ? 'Vide = lien général de Réglages → Entreprise.' : 'Aucun lien général : ajoutez-en un dans Réglages → Entreprise, ou collez ici un lien créé dans l\'application myPOS.'" />
                    <p class="small muted">Quand le client a payé, enregistrez le paiement ci-dessus avec le moyen « myPOS ».</p>
                    <div class="form-actions"><button class="btn btn-secondary btn-sm" type="submit">Enregistrer le lien</button></div>
                </form>
            </details>
            @endif
        @endif
    </div>
@endif
