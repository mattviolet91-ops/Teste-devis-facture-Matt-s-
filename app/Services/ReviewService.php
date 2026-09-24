<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\ReviewRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Demande d'avis Google après un chantier payé : email automatique quand le
 * client a une adresse, sinon rappel pour l'envoyer par SMS / WhatsApp.
 * Un même client n'est sollicité qu'une fois par an.
 */
class ReviewService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly EmailComposer $composer,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('reviews.enabled') && $this->googleUrl() !== '';
    }

    public function googleUrl(): string
    {
        return trim((string) $this->settings->get('reviews.google_url'));
    }

    /** Factures payées depuis assez longtemps, dont le client n'a pas été sollicité depuis un an. */
    public function due(): Collection
    {
        if (! $this->isEnabled()) {
            return collect();
        }
        $delay = max(0, (int) $this->settings->get('reviews.delay_days', 2));
        // Seulement les chantiers payés après l'activation : pas de relance des anciens clients.
        $since = Carbon::parse($this->settings->get('reviews.enabled_at') ?? now());

        return Invoice::query()->invoices()->where('status', 'paid')->whereIn('kind', ['standard', 'final'])
            ->whereDate('paid_at', '<=', today()->subDays($delay))->where('paid_at', '>=', $since->copy()->startOfDay())
            ->whereDoesntHave('reviewRequest')
            ->whereHas('client', fn ($q) => $q->whereDoesntHave('reviewRequests', fn ($r) => $r->where('status', 'sent')->where('sent_at', '>=', now()->subYear())))
            ->with('client')->get()
            ->unique('client_id');
    }

    public function message(Client $client): string
    {
        return $this->render((string) $this->settings->get('mail.review'), $client);
    }

    public function subject(Client $client): string
    {
        return $this->render((string) $this->settings->get('mail.review_subject'), $client);
    }

    private function render(string $text, Client $client): string
    {
        return $this->composer->renderText(strtr($text, ['{lien_avis}' => $this->googleUrl()]), $client);
    }

    /** Email avec un bouton « Laisser un avis ». */
    public function sendEmail(ReviewRequest $request): bool
    {
        $client = $request->client;
        // Le lien apparaît en bouton : on le retire du texte.
        $body = trim(preg_replace('/^.*'.preg_quote($this->googleUrl(), '/').'.*$\R?/m', '', $this->message($client)));
        $log = app(EmailService::class)->send($client, null, [$client->email], [], $this->subject($client), $body, false,
            buttonUrl: $this->googleUrl(), buttonLabel: 'Laisser un avis Google');

        if ($log->isSent()) {
            $this->markSent($request, 'email');
        }

        return $log->isSent();
    }

    public function markSent(ReviewRequest $request, string $channel): void
    {
        $request->forceFill(['status' => 'sent', 'channel' => $channel, 'sent_at' => now()])->save();
        ActivityLogger::log('review.requested', 'Demande d\'avis Google envoyée par '.(ReviewRequest::CHANNELS[$channel] ?? $channel), $request->client);
    }
}
