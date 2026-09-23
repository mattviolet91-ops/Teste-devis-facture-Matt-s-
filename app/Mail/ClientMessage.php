<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Email rédigé à partir d'un modèle, avec le PDF du document en pièce jointe. */
class ClientMessage extends Mailable
{
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $text,
        public readonly ?string $pdf = null,
        public readonly ?string $pdfName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(html: 'mail.client-message', text: 'mail.client-message-text');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if ($this->pdf === null) {
            return [];
        }

        return [Attachment::fromData(fn () => $this->pdf, $this->pdfName)->withMime('application/pdf')];
    }
}
