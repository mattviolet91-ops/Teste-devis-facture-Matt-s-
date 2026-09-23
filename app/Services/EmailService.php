<?php

namespace App\Services;

use App\Mail\ClientMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\SentEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Envoi d'un email au client, avec le PDF du document si demandé, et historique. */
class EmailService
{
    public function __construct(
        private readonly MailSettings $mail,
        private readonly PdfService $pdf,
        private readonly QuoteService $quotes,
        private readonly InvoiceService $invoices,
        private readonly InsuranceService $insurance,
    ) {}

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public function send(Client $client, Quote|Invoice|null $document, array $to, array $cc, string $subject, string $body, bool $attachPdf, bool $attachInsurance = false): SentEmail
    {
        // Un brouillon envoyé par email reçoit son numéro définitif.
        if ($document && $document->isDraft()) {
            $document instanceof Quote ? $this->quotes->send($document) : $this->invoices->send($document);
        }

        if ($document) {
            $subject = str_replace('{numero}', (string) $document->number, $subject);
            $body = str_replace('{numero}', (string) $document->number, $body);
        }

        $attachment = $document && $attachPdf ? $this->pdf->filename($document) : null;
        $files = [];
        if ($attachInsurance && ($certificate = $this->insurance->currentCertificate())) {
            $extension = pathinfo($certificate->path, PATHINFO_EXTENSION) ?: 'pdf';
            $files[] = ['path' => $certificate->path, 'name' => 'Attestation assurance décennale.'.$extension];
        }

        $log = new SentEmail([
            'client_id' => $client->id,
            'to' => implode(', ', $to),
            'cc' => $cc ? implode(', ', $cc) : null,
            'subject' => $subject,
            'body' => $body,
            'attachment' => collect([$attachment, $files ? 'Attestation d\'assurance' : null])->filter()->implode(' + ') ?: null,
            'sent_by' => auth()->id(),
        ]);
        $log->document()->associate($document);

        try {
            $message = Mail::to($to)->cc($cc);
            if ($bcc = $this->mail->bccAddress()) {
                $message->bcc($bcc);
            }
            $message->send(new ClientMessage($subject, $body, $attachment ? $this->pdf->content($document) : null, $attachment, $files));
            $log->status = 'sent';
        } catch (Throwable $e) {
            Log::warning('Échec d\'envoi d\'email', ['error' => $e->getMessage()]);
            $log->status = 'failed';
            $log->error = mb_substr($e->getMessage(), 0, 500);
        }

        $log->save();

        ActivityLogger::log(
            $log->isSent() ? 'email.sent' : 'email.failed',
            ($log->isSent() ? 'Email envoyé à ' : 'Échec de l\'email à ').$log->to.' : « '.$subject.' »',
            $document ?? $client,
        );

        return $log;
    }
}
