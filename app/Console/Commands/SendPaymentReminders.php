<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Services\EmailComposer;
use App\Services\EmailService;
use App\Services\MailSettings;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Console\Command;

/**
 * Relances automatiques des factures en retard (si activées dans Réglages →
 * Emails) : première relance X jours après l'échéance, puis tous les Y jours,
 * dans la limite du nombre maximum choisi.
 */
class SendPaymentReminders extends Command
{
    protected $signature = 'app:payment-reminders';

    protected $description = 'Envoie les relances automatiques des factures impayées';

    public function handle(Settings $settings, MailSettings $mail, EmailComposer $composer, EmailService $emails, PushService $push): int
    {
        $config = $settings->group('reminders');
        if (empty($config['auto_enabled'])) {
            $this->info('Relances automatiques désactivées.');

            return self::SUCCESS;
        }
        if (! $mail->isConfigured()) {
            $this->warn('Envoi des emails non configuré.');

            return self::SUCCESS;
        }

        $template = EmailTemplate::query()->where('context', 'invoice')->where('name', 'like', 'Relance%')->ordered()->first();
        if (! $template) {
            $this->warn('Aucun modèle « Relance » pour les factures.');

            return self::SUCCESS;
        }

        $firstAfter = max(0, (int) $config['first_after_days']);
        $repeat = max(1, (int) $config['repeat_days']);
        $max = max(1, (int) $config['max']);

        $invoices = Invoice::query()->overdue()
            ->whereDate('due_date', '<=', today()->subDays($firstAfter))
            ->where('reminder_count', '<', $max)
            ->where(fn ($q) => $q->whereNull('last_reminder_at')->orWhere('last_reminder_at', '<=', now()->subDays($repeat)->endOfDay()))
            ->whereHas('client', fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->with('client')
            ->get();

        $sent = 0;
        foreach ($invoices as $invoice) {
            $message = $composer->render($template, $invoice->client, $invoice);
            $log = $emails->send($invoice->client, $invoice, [$invoice->client->email], [], $message['subject'], $message['body'], true);
            if ($log->isSent()) {
                $invoice->forceFill(['reminder_count' => $invoice->reminder_count + 1, 'last_reminder_at' => now()])->save();
                $sent++;
            }
        }

        if ($sent) {
            $push->send('Relances envoyées', "$sent relance(s) de paiement envoyée(s) automatiquement.", route('invoices.index', ['status' => 'overdue']));
        }
        $this->info("$sent relance(s) envoyée(s).");

        return self::SUCCESS;
    }
}
