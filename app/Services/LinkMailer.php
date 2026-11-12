<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\SentEmail;
use App\Support\Money;

/**
 * Emails liés aux liens envoyés au client : lien de paiement d'un montant libre,
 * document à signer, et facture envoyée après un paiement par carte.
 */
class LinkMailer
{
    public function __construct(
        private readonly EmailService $emails,
        private readonly MailSettings $mail,
        private readonly Settings $settings,
    ) {}

    public function canSend(): bool
    {
        return $this->mail->isConfigured();
    }

    public function paymentMessage(PaymentRequest $request, bool $reminder = false): string
    {
        return ($reminder ? "Bonjour,\n\nSauf erreur de notre part, le règlement ci-dessous est toujours en attente.\n\n" : "Bonjour,\n\n")
             ."Vous pouvez régler « {$request->label} » (".Money::plain($request->amount).') par carte bancaire, en toute sécurité, avec le bouton ci-dessous. '
            ."Un reçu vous est envoyé par email après le paiement.\n\nMerci,\n".$this->settings->get('company.trade_name');
    }

    public function signatureMessage(Attachment $attachment, bool $reminder = false): string
    {
        return ($reminder ? "Bonjour,\n\nSauf erreur de notre part, le document ci-dessous attend toujours votre signature.\n\n" : "Bonjour,\n\n")
            ."Vous pouvez lire et signer en ligne le document « {$attachment->name} » avec le bouton ci-dessous, depuis votre téléphone ou votre ordinateur.\n\nMerci,\n"
            .$this->settings->get('company.trade_name');
    }

    public function sendPaymentLink(PaymentRequest $request, string $to, ?string $message = null, bool $reminder = false): SentEmail
    {
        return $this->emails->send(
            $request->client, null, [$to], [],
            ($reminder ? 'Rappel : ' : '').'Paiement par carte — '.$request->label,
            $message ?: $this->paymentMessage($request, $reminder),
            false, false,
            $request->publicUrl().(app(MyposGateway::class)->isTest() ? '?essai=1' : ''),
            'Payer '.Money::plain($request->amount).' par carte',
        );
    }

    public function sendSignatureLink(Attachment $attachment, string $to, ?string $message = null, bool $reminder = false): SentEmail
    {
        return $this->emails->send(
            $attachment->client, null, [$to], [],
            ($reminder ? 'Rappel : ' : '').'Document à signer — '.$attachment->name,
            $message ?: $this->signatureMessage($attachment, $reminder),
            false, false,
            $attachment->publicUrl(),
            'Lire et signer le document',
        );
    }

    /** Encaissement sans facture payé par carte : simple reçu par email (la facture suivra). */
    public function sendPaymentReceipt(Payment $payment): ?SentEmail
    {
        $client = $payment->client;
        if (! $client?->email || ! $this->canSend() || ! $this->settings->get('mypos.email_receipt', true)) {
            return null;
        }

        return $this->emails->send(
            $client, null, [$client->email], [],
            'Reçu de paiement — '.$this->settings->get('company.trade_name'),
            "Bonjour,\n\nNous avons bien reçu votre paiement par carte de ".Money::plain($payment->amount).' le '.$payment->paid_at->format('d/m/Y')
            .($payment->label ? " pour « {$payment->label} »" : '').". Merci !\n\nCe message vaut reçu ; la facture correspondante vous sera adressée séparément.\n\nCordialement,\n"
            .$this->settings->get('company.trade_name'),
            false,
        );
    }

    /** Après un paiement par carte : la facture (PDF joint) est envoyée au client, s'il a une adresse email. */
    public function sendReceipt(Invoice $invoice, int $amount): ?SentEmail
    {
        $to = $invoice->client?->email;
        if (! $to || ! $this->canSend() || ! $this->settings->get('mypos.email_receipt', true)) {
            return null;
        }
        $invoice->refresh();
        $rest = $invoice->balance() > 0 ? ' Reste à régler : '.Money::plain($invoice->balance()).'.' : '';

        return $this->emails->send(
            $invoice->client, $invoice, [$to], [],
            'Votre paiement — '.$invoice->kindLabel().' '.$invoice->number,
            "Bonjour,\n\nNous avons bien reçu votre paiement par carte de ".Money::plain($amount).'. Merci !'.$rest
            ."\n\nVous trouverez votre ".mb_strtolower($invoice->kindLabel())." en pièce jointe ; elle reste consultable avec le bouton ci-dessous.\n\nCordialement,\n"
            .$this->settings->get('company.trade_name'),
            true,
        );
    }
}
