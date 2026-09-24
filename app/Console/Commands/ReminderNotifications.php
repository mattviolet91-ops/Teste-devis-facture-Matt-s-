<?php

namespace App\Console\Commands;

use App\Mail\ClientMessage;
use App\Models\Invoice;
use App\Models\PushSubscription;
use App\Services\MailSettings;
use App\Services\PushService;
use App\Services\Settings;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Chaque matin : notification pour les factures à relancer (3 jours avant
 * l'échéance, le jour même, puis 1, 7, 15 et 30 jours de retard).
 */
class ReminderNotifications extends Command
{
    /** Écarts (en jours) avec l'échéance qui déclenchent une notification. */
    public const STEPS = [-3, 0, 1, 7, 15, 30];

    protected $signature = 'app:reminder-notifications';

    protected $description = 'Prévient des factures qui arrivent à échéance ou sont en retard';

    public function handle(Settings $settings, PushService $push, MailSettings $mail): int
    {
        if (! $settings->get('reminders.notify_enabled', true)) {
            $this->info('Notifications de relance désactivées.');

            return self::SUCCESS;
        }

        $dates = array_map(fn ($offset) => today()->subDays($offset)->toDateString(), self::STEPS);
        $invoices = Invoice::query()->invoices()->whereIn('status', Invoice::OPEN)
            ->where(function ($q) use ($dates) {
                foreach ($dates as $date) {
                    $q->orWhereDate('due_date', $date);
                }
            })
            ->with('client')->orderBy('due_date')->get();

        if ($invoices->isEmpty()) {
            $this->info('Aucune facture à relancer aujourd\'hui.');

            return self::SUCCESS;
        }

        $lines = $invoices->map(fn (Invoice $invoice) => $this->summary($invoice));

        if ($invoices->count() <= 3) {
            foreach ($invoices as $i => $invoice) {
                $push->send('À relancer : '.$invoice->client?->displayName(), $lines[$i].' Appuyez pour relancer.', route('reminders.show', $invoice));
            }
        } else {
            $push->send($invoices->count().' factures à relancer', $lines->take(3)->implode(' · ').'…', route('reminders.index'));
        }

        // Sans téléphone abonné : résumé par email.
        if (PushSubscription::query()->doesntExist() && $mail->isConfigured() && $settings->get('company.email')) {
            try {
                $mail->apply();
                Mail::to($settings->get('company.email'))->send(new ClientMessage(
                    'Factures à relancer aujourd\'hui',
                    "Bonjour,\n\n".$lines->map(fn ($l) => '• '.$l)->implode("\n"),
                    buttonUrl: route('reminders.index'),
                    buttonLabel: 'Voir les relances',
                ));
            } catch (Throwable $e) {
                $this->warn('Email non envoyé : '.$e->getMessage());
            }
        }

        $this->info($invoices->count().' facture(s) signalée(s).');

        return self::SUCCESS;
    }

    private function summary(Invoice $invoice): string
    {
        $late = (int) $invoice->due_date->diffInDays(today(), false);
        $when = match (true) {
            $late < 0 => 'échéance dans '.(-$late).' jours ('.$invoice->due_date->format('d/m').')',
            $late === 0 => 'échéance aujourd\'hui',
            default => 'en retard de '.$late.' jour'.($late > 1 ? 's' : ''),
        };

        return "{$invoice->number} ({$invoice->client?->displayName()}, ".str_replace(["\u{202F}", "\u{00A0}"], ' ', Money::format($invoice->balance())).") : $when.";
    }
}
