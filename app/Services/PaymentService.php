<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Enregistrement des règlements et mise à jour du statut des factures. */
class PaymentService
{
    /** @param  array{paid_at: string, amount: int, method: string, method_detail?: ?string, reference?: ?string, notes?: ?string}  $data */
    public function record(Invoice $invoice, array $data): Payment
    {
        if (! $invoice->acceptsPayments()) {
            throw new InvalidArgumentException('Cette facture ne peut pas recevoir de paiement.');
        }
        if ($data['amount'] <= 0) {
            throw new InvalidArgumentException('Le montant doit être positif.');
        }
        if ($data['amount'] > $invoice->balance()) {
            throw new InvalidArgumentException('Le montant dépasse le reste à payer ('.Money::format($invoice->balance()).').');
        }

        $payment = DB::transaction(function () use ($invoice, $data) {
            $payment = new Payment($data);
            $payment->invoice()->associate($invoice);
            $payment->client_id = $invoice->client_id;
            $payment->created_by = auth()->id();
            $payment->save();

            $this->refresh($invoice);
            ActivityLogger::log(
                'payment.recorded',
                'Paiement de '.Money::format($payment->amount).' ('.$payment->methodLabel().") reçu sur la facture {$invoice->number}",
                $invoice,
            );

            return $payment;
        });

        // Le PDF de la facture affiche désormais ce règlement (date, moyen, montant).
        app(PdfService::class)->regenerate($invoice);

        return $payment;
    }

    /**
     * Montant libre encaissé sans facture : enregistré seul, à rattacher ensuite à une facture existante.
     *
     * @param  array{paid_at: string, amount: int, label: string, method: string, method_detail?: ?string, reference?: ?string, notes?: ?string}  $data
     */
    public function recordUnallocated(Client $client, array $data): Payment
    {
        if ($data['amount'] <= 0) {
            throw new InvalidArgumentException('Le montant doit être positif.');
        }

        $payment = new Payment($data);
        $payment->client_id = $client->id;
        $payment->created_by = auth()->id();
        $payment->save();
        ActivityLogger::log('payment.recorded', 'Encaissement de '.Money::format($payment->amount).' ('.$payment->methodLabel().") sans facture : {$payment->label}", $client);

        return $payment;
    }

    /** Rattache un encaissement en attente à une facture envoyée du même client. */
    public function attach(Payment $payment, Invoice $invoice): Payment
    {
        if (! $payment->isUnallocated()) {
            throw new InvalidArgumentException('Ce paiement est déjà rattaché à une facture.');
        }
        if ($invoice->client_id !== $payment->client_id) {
            throw new InvalidArgumentException('Cette facture n\'est pas celle de ce client.');
        }
        if (! $invoice->acceptsPayments()) {
            throw new InvalidArgumentException('Cette facture ne peut pas recevoir de paiement (brouillon, déjà réglée ou annulée).');
        }
        if ($payment->amount > $invoice->balance()) {
            throw new InvalidArgumentException('L\'encaissement ('.Money::format($payment->amount).') dépasse le reste à payer de cette facture ('.Money::format($invoice->balance()).').');
        }

        DB::transaction(function () use ($payment, $invoice) {
            $payment->invoice()->associate($invoice);
            $payment->save();
            $this->refresh($invoice);
            ActivityLogger::log('payment.attached', 'Encaissement de '.Money::format($payment->amount)." rattaché à la facture {$invoice->number}", $invoice);
        });
        app(PdfService::class)->regenerate($invoice);

        return $payment;
    }

    public function delete(Payment $payment): void
    {
        $invoice = $payment->invoice;
        DB::transaction(function () use ($payment, $invoice) {
            $payment->delete();
            if ($invoice) {
                $this->refresh($invoice);
            }
            ActivityLogger::log('payment.deleted', 'Paiement de '.Money::format($payment->amount).' supprimé'.($invoice ? " sur la facture {$invoice->number}" : ' (sans facture)'), $invoice ?? $payment->client);
        });
        if ($invoice) {
            app(PdfService::class)->regenerate($invoice);
        }
    }

    /** Recalcule le montant réglé et le statut (envoyée, partiellement payée, payée). */
    public function refresh(Invoice $invoice): Invoice
    {
        $paid = (int) $invoice->payments()->sum('amount');
        $attributes = ['amount_paid' => $paid];

        if (in_array($invoice->status, Invoice::ISSUED, true)) {
            $attributes['status'] = match (true) {
                $paid >= $invoice->total_ttc => 'paid',
                $paid > 0 => 'partial',
                default => 'sent',
            };
            $attributes['paid_at'] = $attributes['status'] === 'paid' ? $invoice->payments()->max('paid_at') : null;
        }

        $invoice->forceFill($attributes)->save();

        return $invoice;
    }

    /**
     * Facture corrigée (« Modifier ») : les règlements déjà reçus passent sur la
     * nouvelle facture une fois celle-ci envoyée.
     */
    public function transfer(Invoice $from, Invoice $to): void
    {
        if (! $from->payments()->exists()) {
            return;
        }

        DB::transaction(function () use ($from, $to) {
            $from->payments()->update(['invoice_id' => $to->id]);
            $this->refresh($from->fresh());
            $this->refresh($to->fresh());
            ActivityLogger::log('payment.transferred', "Paiements de la facture {$from->number} reportés sur la facture {$to->number}", $to);
        });
        app(PdfService::class)->regenerate($to);
    }
}
