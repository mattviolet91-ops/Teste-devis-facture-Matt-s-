<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use App\Models\Quote;
use App\Services\EmailComposer;
use App\Services\EmailService;
use App\Services\MailSettings;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Console\Command;

/**
 * Relance automatique des devis envoyés sans réponse (si activée dans
 * Réglages → Emails) : un email X jours après l'envoi, puis un second Y jours
 * après l'envoi. Jamais pour un devis signé, refusé, expiré ou remplacé.
 */
class SendQuoteFollowUps extends Command
{
    protected $signature = 'app:quote-follow-ups';

    protected $description = 'Relance par email les devis envoyés restés sans réponse';

    public function handle(Settings $settings, MailSettings $mail, EmailComposer $composer, EmailService $emails, PushService $push): int
    {
        $config = $settings->group('reminders');
        if (empty($config['quotes_auto'])) {
            $this->info('Relance des devis désactivée.');

            return self::SUCCESS;
        }
        if (! $mail->isConfigured()) {
            $this->warn('Envoi des emails non configuré.');

            return self::SUCCESS;
        }

        $template = EmailTemplate::query()->where('context', 'quote')->where('name', 'like', 'Relance%')->ordered()->first();
        if (! $template) {
            $this->warn('Aucun modèle « Relance du devis ».');

            return self::SUCCESS;
        }

        // Étapes : 1re relance à X jours, 2e à Y jours (après l'envoi).
        $steps = array_values(array_filter([(int) $config['quotes_first_days'], (int) $config['quotes_second_days']], fn ($d) => $d > 0));
        sort($steps);

        $quotes = Quote::query()->where('status', 'sent')->whereNull('signed_at')->whereNotNull('sent_at')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->where('follow_up_count', '<', count($steps))
            ->whereHas('client', fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->with('client')->get();

        $sent = [];
        foreach ($quotes as $quote) {
            $due = $steps[$quote->follow_up_count] ?? null;
            if ($due === null || $quote->sent_at->copy()->startOfDay()->addDays($due)->isFuture()) {
                continue;
            }
            // Jamais deux relances le même jour ni à moins de 3 jours d'intervalle.
            if ($quote->last_follow_up_at && $quote->last_follow_up_at->gt(now()->subDays(3))) {
                continue;
            }

            $message = $composer->render($template, $quote->client, $quote);
            $log = $emails->send($quote->client, $quote, [$quote->client->email], [], $message['subject'], $message['body'], false);
            if ($log->isSent()) {
                $quote->forceFill(['follow_up_count' => $quote->follow_up_count + 1, 'last_follow_up_at' => now()])->save();
                $sent[] = $quote;
            }
        }

        if ($sent) {
            $push->send(
                count($sent) === 1 ? 'Devis relancé : '.$sent[0]->client->displayName() : count($sent).' devis relancés',
                count($sent) === 1 ? "Le devis {$sent[0]->number} a été relancé automatiquement par email." : 'Relances envoyées automatiquement par email : '.collect($sent)->pluck('number')->implode(', ').'.',
                count($sent) === 1 ? route('quotes.show', $sent[0]) : route('quotes.index', ['status' => 'sent']),
            );
        }
        $this->info(count($sent).' devis relancé(s).');

        return self::SUCCESS;
    }
}
