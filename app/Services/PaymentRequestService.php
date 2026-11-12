<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Quote;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Encaisser un montant libre : soit déjà reçu (espèces, chèque, virement, terminal),
 * soit par carte avec un lien envoyé au client. Aucune facture n'est créée : le paiement
 * est enregistré sur la facture existante choisie, sinon comme « encaissement à rattacher ».
 */
class PaymentRequestService
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * Délai légal de 7 jours (art. L221-10) : rien ne peut être encaissé d'un client dont le devis
     * a été signé à domicile il y a moins de 7 jours (sauf urgence confirmée).
     */
    public function legalProblem(Client $client, ?Invoice $invoice = null): ?string
    {
        if ($invoice && ($from = $invoice->paymentWaitUntil())) {
            return "Le devis de la facture {$invoice->number} a été signé à domicile il y a moins de 7 jours : aucun paiement ne peut être reçu avant le {$from->format('d/m/Y')} (délai légal), sauf intervention urgente confirmée par le client.";
        }
        $waiting = $client->quotes()->where('status', 'accepted')->get()
            ->first(fn (Quote $q) => ($from = $q->paymentAllowedFrom()) && $from->isFuture());
        if ($waiting && (! $invoice || $invoice->quote_id === $waiting->id)) {
            return "Le devis {$waiting->number} de ce client a été signé à domicile il y a moins de 7 jours : aucun paiement ne peut être reçu avant le {$waiting->paymentAllowedFrom()->format('d/m/Y')} (délai légal), sauf intervention urgente confirmée par le client.";
        }

        return null;
    }

    /**
     * Montant déjà reçu : enregistré sur la facture choisie, sinon comme encaissement à rattacher.
     *
     * @param  array{label: string, amount: int, paid_at: string, method: string, method_detail?: ?string, reference?: ?string}  $data
     */
    public function recordReceived(Client $client, ?Invoice $invoice, array $data): Payment
    {
        $this->guard($client, $invoice, $data['amount']);
        $fields = [
            'paid_at' => $data['paid_at'],
            'amount' => $data['amount'],
            'label' => $data['label'],
            'method' => $data['method'],
            'method_detail' => $data['method_detail'] ?? null,
            'reference' => $data['reference'] ?? null,
        ];

        return $invoice ? $this->payments->record($invoice, $fields) : $this->payments->recordUnallocated($client, $fields);
    }

    /** Lien de paiement par carte à envoyer au client, pour une facture existante ou sans facture. */
    public function createRequest(Client $client, ?Invoice $invoice, string $label, int $amount): PaymentRequest
    {
        $this->guard($client, $invoice, $amount);

        $request = new PaymentRequest(['label' => $label]);
        $request->forceFill([
            'client_id' => $client->id,
            'invoice_id' => $invoice?->id,
            'invoice_kind' => 'standard',
            'amount' => $amount,
            'token' => Str::random(48),
            'status' => 'pending',
            'created_by' => auth()->id(),
        ])->save();
        ActivityLogger::log('payment_request.created', 'Demande de paiement de '.Money::format($amount)." : $label", $invoice ?? $client);

        return $request;
    }

    /**
     * Paiement depuis le lien permanent : rien n'est rattaché à un client tant que myPOS n'a pas confirmé.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone?: ?string, label: string, amount: int}  $payer
     *
     * @throws InvalidArgumentException message à montrer tel quel
     */
    public function createOpenRequest(array $payer): PaymentRequest
    {
        // Client déjà connu (même email) : le délai légal de 7 jours d'un devis signé à domicile s'applique.
        $known = $this->findClientByEmail($payer['email']);
        if ($known && $this->legalProblem($known)) {
            throw new InvalidArgumentException('Le paiement sera possible à l\'issue du délai légal de 7 jours après la signature de votre devis. Nous vous enverrons la facture à ce moment-là.');
        }

        $request = new PaymentRequest(['label' => $payer['label']]);
        $request->forceFill([
            'payer_first_name' => $payer['first_name'],
            'payer_last_name' => $payer['last_name'],
            'payer_email' => mb_strtolower(trim($payer['email'])),
            'payer_phone' => $payer['phone'] ?? null,
            'invoice_kind' => 'standard',
            'amount' => $payer['amount'],
            'token' => Str::random(48),
            'status' => 'pending',
        ])->save();

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
     * Paiement par carte confirmé par myPOS : enregistré sur la facture visée si elle peut encore
     * le recevoir, sinon comme encaissement à rattacher. Aucune facture n'est créée.
     *
     * @return array{message: string, payment: ?Payment}
     */
    public function settle(OnlinePayment $attempt, int $amount): array
    {
        $request = PaymentRequest::query()->whereKey($attempt->payment_request_id)->lockForUpdate()->firstOrFail();
        $name = $request->client?->displayName() ?? $request->payerName();

        if ($request->status === 'paid') {
            $attempt->forceFill(['error' => 'Demande déjà réglée'])->save();

            return ['message' => 'Second paiement par carte de '.Money::plain($amount)." reçu pour « {$request->label} », déjà réglé. Vérifiez dans myPOS et remboursez si besoin.", 'payment' => null];
        }
        if ($amount !== $request->amount) {
            $attempt->forceFill(['error' => 'Montant différent de la demande'])->save();

            return ['message' => 'Paiement par carte de '.Money::plain($amount)." reçu pour « {$request->label} » (demandé : ".Money::plain($request->amount).'), non enregistré. Vérifiez dans myPOS.', 'payment' => null];
        }

        // Tout ou rien : le paiement et l'état de la demande sont enregistrés ensemble.
        $payment = DB::transaction(function () use ($request, $attempt, $amount) {
            // Lien permanent : la fiche client est retrouvée par l'email, sinon créée maintenant.
            if (! $request->client) {
                $request->client()->associate($this->clientOfPayer($request));
            }
            $fields = [
                'paid_at' => today()->toDateString(),
                'amount' => $amount,
                'label' => $request->label,
                'method' => 'mypos',
                'method_detail' => 'Carte en ligne',
                'reference' => $attempt->transaction_ref ?? $attempt->order_id,
                'notes' => 'Payé en ligne par le client',
            ];
            $invoice = $request->invoice;
            $payment = $invoice && $invoice->acceptsPayments() && $amount <= $invoice->balance()
                ? $this->payments->record($invoice, $fields)
                : $this->payments->recordUnallocated($request->client, $fields);

            $attempt->forceFill(['payment_id' => $payment->id, 'invoice_id' => $payment->invoice_id])->save();
            $request->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            return $payment;
        });

        $where = $payment->invoice
            ? "Le paiement est enregistré sur la facture {$payment->invoice->number}."
            : 'Il est à rattacher à une facture (Paiements → À rattacher).';

        return ['message' => "$name a payé ".Money::plain($amount)." par carte en ligne pour « {$request->label} ». $where", 'payment' => $payment];
    }

    private function findClientByEmail(string $email): ?Client
    {
        return Client::query()->where('email', mb_strtolower(trim($email)))->orderBy('id')->first();
    }

    private function clientOfPayer(PaymentRequest $request): Client
    {
        if ($client = $this->findClientByEmail((string) $request->payer_email)) {
            return $client;
        }

        $client = Client::query()->create([
            'type' => 'particulier',
            'status' => 'client',
            'first_name' => $request->payer_first_name,
            'last_name' => $request->payer_last_name,
            'email' => $request->payer_email,
            'phone' => $request->payer_phone,
            'source' => 'site',
            'source_detail' => 'Lien de paiement à montant libre',
        ]);
        ActivityLogger::log('client.created', "Client créé par un paiement en ligne : {$client->displayName()}", $client);

        return $client;
    }

    /** @throws InvalidArgumentException message à montrer tel quel */
    private function guard(Client $client, ?Invoice $invoice, int $amount): void
    {
        if ($invoice) {
            if ($invoice->client_id !== $client->id) {
                throw new InvalidArgumentException('Cette facture n\'est pas celle de ce client.');
            }
            if (! $invoice->acceptsPayments()) {
                throw new InvalidArgumentException("La facture {$invoice->number} ne peut pas recevoir de paiement (déjà réglée ou annulée).");
            }
            if ($amount > $invoice->balance()) {
                throw new InvalidArgumentException("Ce montant dépasse le reste à payer de la facture {$invoice->number} (".Money::format($invoice->balance()).').');
            }
        }
        if ($problem = $this->legalProblem($client, $invoice)) {
            throw new InvalidArgumentException($problem);
        }
    }
}
