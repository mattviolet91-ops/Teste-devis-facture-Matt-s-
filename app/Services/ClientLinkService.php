<?php

namespace App\Services;

use App\Mail\ClientMessage;
use App\Models\Invoice;
use App\Models\Quote;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Actions du client depuis son lien : consultation, acceptation signée,
 * refus ou demande de modification. Chaque action est tracée et vous est
 * signalée par email.
 */
class ClientLinkService
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly PdfService $pdf,
        private readonly MailSettings $mail,
        private readonly Settings $settings,
        private readonly PushService $push,
    ) {}

    /** Première ouverture du lien : date enregistrée et notification. */
    public function markViewed(Quote|Invoice $document): void
    {
        if ($document->viewed_at) {
            return;
        }

        $document->forceFill(['viewed_at' => now()])->saveQuietly();
        $label = $document instanceof Quote ? "Devis {$document->number}" : "{$document->kindLabel()} {$document->number}";
        ActivityLogger::log('link.viewed', "$label consulté par le client", $document);
        $this->notify("$label consulté", "{$document->client?->displayName()} vient d'ouvrir $label.", $document);
    }

    /**
     * Acceptation en ligne : nom, signature (image PNG), date, heure et adresse IP.
     * Le PDF est figé de nouveau avec la signature.
     */
    public function sign(Quote $quote, string $name, string $signatureDataUrl, ?string $ip, ?string $userAgent, bool $onSite = false): Quote
    {
        $png = $this->decodeSignature($signatureDataUrl);

        return DB::transaction(function () use ($quote, $name, $png, $ip, $userAgent, $onSite) {
            $path = 'signatures/'.$quote->number.'-'.Str::random(8).'.png';
            Storage::disk('local')->put($path, $png);

            $quote->forceFill([
                'signed_name' => $name,
                'signature_path' => $path,
                'signed_at' => now(),
                'signed_ip' => $ip,
                'signed_user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'signed_on_site' => $onSite,
            ])->save();
            $this->quotes->accept($quote);
            ActivityLogger::log('link.signed', "Devis {$quote->number} signé ".($onSite ? 'sur place' : 'en ligne')." par $name", $quote, ['ip' => $ip]);

            // Version signée du PDF, avec son empreinte : c'est elle qui est servie désormais.
            $this->pdf->freeze($quote->fresh());

            // Signé devant l'artisan : inutile de le prévenir.
            if ($onSite) {
                return $quote;
            }

            $this->notify(
                "Devis {$quote->number} accepté",
                "Bonne nouvelle : {$quote->client?->displayName()} a accepté et signé en ligne le devis {$quote->number} "
                ."({$this->money($quote->total_ttc)}) le ".now()->format('d/m/Y à H:i').'.',
                $quote,
            );

            return $quote;
        });
    }

    public function refuse(Quote $quote, ?string $comment): Quote
    {
        $this->quotes->refuse($quote, $comment ? 'Refus en ligne : '.$comment : 'Refusé en ligne par le client');
        $this->notify(
            "Devis {$quote->number} refusé",
            "{$quote->client?->displayName()} a refusé le devis {$quote->number} en ligne.".($comment ? "\n\nSon message :\n$comment" : ''),
            $quote,
        );

        return $quote;
    }

    public function requestChange(Quote $quote, string $comment): Quote
    {
        $quote->forceFill(['client_comment' => $comment, 'change_requested_at' => now()])->save();
        ActivityLogger::log('link.change_requested', "Demande de modification du client sur le devis {$quote->number}", $quote);
        $this->notify(
            "Demande de modification — devis {$quote->number}",
            "{$quote->client?->displayName()} demande une modification du devis {$quote->number} :\n\n$comment\n\n"
            .'Créez une nouvelle version depuis le devis pour lui répondre.',
            $quote,
        );

        return $quote;
    }

    /** Vérifie et décode l'image de signature envoyée par le navigateur. */
    private function decodeSignature(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new InvalidArgumentException('Signature invalide.');
        }

        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) > 600_000 || ! str_starts_with($png, "\x89PNG") || @imagecreatefromstring($png) === false) {
            throw new InvalidArgumentException('Signature invalide.');
        }

        return $png;
    }

    private function notify(string $subject, string $text, Quote|Invoice $document): void
    {
        // Notification sur le téléphone (si activée), puis email.
        $this->push->send($subject, $text, $document instanceof Quote ? route('quotes.show', $document) : route('invoices.show', $document));

        $to = $this->settings->get('company.email');
        if (! $to || ! $this->mail->isConfigured()) {
            return;
        }

        $url = $document instanceof Quote ? route('quotes.show', $document) : route('invoices.show', $document);

        try {
            Mail::to($to)->send(new ClientMessage($subject, "Bonjour,\n\n$text", buttonUrl: $url, buttonLabel: 'Ouvrir dans l\'application'));
        } catch (Throwable $e) {
            Log::warning('Notification non envoyée', ['error' => $e->getMessage()]);
        }
    }

    private function money(int $cents): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', Money::format($cents));
    }
}
