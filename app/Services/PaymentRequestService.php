<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\PaymentRequest;
use App\Models\Quote;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Encaisser un montant libre : soit déjà reçu (espèces, chèque, virement, terminal),
 * soit par carte avec un lien envoyé au client. Chaque encaissement a sa facture
 * (acompte ou simple), créée, numérotée et marquée réglée au moment du paiement.
 */
class PaymentRequestService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly ClientLinkService $links,
    ) {}

    /**
     * Délai légal de 7 jours (art. L221-10) : rien ne peut être encaissé sur un devis signé
     * à domicile il y a moins de 7 jours, ni en dehors de ce devis tant que le délai court.
     */
    public function legalProblem(Client $client, ?Quote $quote): ?string
    {
        if ($quote && ($from = $quote->paymentAllowedFrom()) && $from->isFuture()) {
            return "Le devis {$quote->number} a été signé à domicile il y a moins de 7 jours : aucun paiement ne peut être reçu avant le {$from->format('d/m/Y')} (délai légal), sauf intervention urgente confirmée par le client.";
        }
        if (! $quote) {
            $waiting = $client->quotes()->where('status', 'accepted')->get()
                ->first(fn (Quote $q) => ($from = $q->paymentAllowedFrom()) && $from->isFuture());
            if ($waiting) {
                return "Le devis {$waiting->number} de ce client a été signé à domicile il y a moins de 7 jours : rattachez l'encaissement à ce devis. Aucun paiement ne peut être reçu avant le {$waiting->paymentAllowedFrom()->format('d/m/Y')}.";
            }
        }

        return null;
    }

    /**
     * Montant déjà reçu : facture créée, envoyée (numéro) et réglée tout de suite.
     *
     * @param  array{kind: string, label: string, amount: int, vat_rate: int, paid_at: string, method: string, method_detail?: ?string, reference?: ?string}  $data
     */
    public function recordReceived(Client $client, ?Quote $quote, array $data): Invoice
    {
        $this->guard($client, $quote, $data['amount']);

        $invoice = DB::transaction(function () use ($client, $quote, $data) {
            $invoice = $this->invoices->send($this->invoices->createForAmount($client, $quote, $data['kind'], $data['label'], $data['amount'], $data['vat_rate']));
            $this->payments->record($invoice, [
                'paid_at' => $data['paid_at'],
                'amount' => $invoice->fresh()->total_ttc,
                'method' => $data['method'],
                'method_detail' => $data['method_detail'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => 'Montant libre encaissé',
            ]);

            return $invoice;
        });

        return $invoice->fresh();
    }

    /** Lien de paiement par carte à envoyer au client (aucune facture tant qu'il n'a pas payé). */
    public function createRequest(Client $client, ?Quote $quote, string $kind, string $label, int $amount, int $vatRate): PaymentRequest
    {
        $this->guard($client, $quote, $amount);

        $request = new PaymentRequest(['label' => $label]);
        $request->forceFill([
            'client_id' => $client->id,
            'quote_id' => $quote?->id,
            'invoice_kind' => $kind,
            'amount' => $amount,
            'vat_rate' => $vatRate,
            'token' => Str::random(48),
            'status' => 'pending',
            'created_by' => auth()->id(),
        ])->save();
        ActivityLogger::log('payment_request.created', 'Demande de paiement de '.Money::format($amount)." : $label", $client);

        return $request;
    }

    public function cancel(PaymentRequest $request): void
    {
        if (! $request->isPending()) {
            return;
        }
        $request->forceFill(['status' => 'cancelled'])->save();
        ActivityLogger::log('payment_request.cancelled', 'Demande de paiement de '.Money::format($request->amount).' annulée', $request->client);
    }

    /**
     * Paiement par carte confirmé par myPOS : la facture est créée et réglée.
     * Renvoie le message de notification.
     */
    public function settle(OnlinePayment $attempt, int $amount): string
    {
        $request = PaymentRequest::query()->whereKey($attempt->payment_request_id)->lockForUpdate()->firstOrFail();
        $name = $request->client?->displayName();

        if ($request->status === 'paid') {
            $attempt->forceFill(['error' => 'Demande déjà réglée'])->save();

            return 'Second paiement par carte de '.Money::plain($amount)." reçu pour « {$request->label} », déjà réglé. Vérifiez dans myPOS et remboursez si besoin.";
        }
        if ($amount !== $request->amount) {
            $attempt->forceFill(['error' => 'Montant différent de la demande'])->save();

            return 'Paiement par carte de '.Money::plain($amount)." reçu pour « {$request->label} » (demandé : ".Money::plain($request->amount).'), non enregistré. Vérifiez dans myPOS.';
        }

        // Tout ou rien : sans paiement enregistré, pas de facture numérotée.
        $invoice = DB::transaction(function () use ($request, $attempt, $amount) {
            $invoice = $this->invoices->send($this->invoices->createForAmount($request->client, $request->quote, $request->invoice_kind, $request->label, $request->amount, $request->vat_rate));
            $payment = $this->payments->record($invoice, [
                'paid_at' => today()->toDateString(),
                'amount' => $amount,
                'method' => 'mypos',
                'method_detail' => 'Carte en ligne',
                'reference' => $attempt->transaction_ref ?? $attempt->order_id,
                'notes' => 'Payé en ligne par le client (montant libre)',
            ]);
            $attempt->forceFill(['payment_id' => $payment->id, 'invoice_id' => $invoice->id])->save();
            $request->forceFill(['status' => 'paid', 'paid_at' => now(), 'invoice_id' => $invoice->id])->save();

            return $invoice;
        });

        return "$name a payé ".Money::plain($amount)." par carte en ligne pour « {$request->label} ». La {$invoice->kindLabel()} {$invoice->number} est créée et réglée.";
    }

    /** @throws InvalidArgumentException message à montrer tel quel */
    private function guard(Client $client, ?Quote $quote, int $amount): void
    {
        if ($quote && $quote->client_id !== $client->id) {
            throw new InvalidArgumentException('Ce devis n\'appartient pas à ce client.');
        }
        if ($quote && $quote->status !== 'accepted') {
            throw new InvalidArgumentException("Le devis {$quote->number} n'est pas accepté : un encaissement ne peut être rattaché qu'à un devis accepté.");
        }
        if ($problem = $this->legalProblem($client, $quote)) {
            throw new InvalidArgumentException($problem);
        }
        if ($quote && $amount > ($remaining = $this->invoices->remainingToInvoice($quote)) + 5) {
            throw new InvalidArgumentException("Ce montant dépasse ce qu'il reste à facturer sur le devis {$quote->number} (".Money::format($remaining).'). Réduisez-le, ou encaissez sans rattacher au devis pour des travaux en plus.');
        }
    }
}
