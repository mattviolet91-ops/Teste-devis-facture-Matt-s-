<?php

namespace App\Services;

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

        return DB::transaction(function () use ($invoice, $data) {
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
    }

    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $invoice = $payment->invoice;
            $payment->delete();
            $this->refresh($invoice);
            ActivityLogger::log('payment.deleted', 'Paiement de '.Money::format($payment->amount)." supprimé sur la facture {$invoice->number}", $invoice);
        });
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
    }
}
