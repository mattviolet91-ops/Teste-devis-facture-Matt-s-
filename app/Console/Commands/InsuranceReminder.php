<?php

namespace App\Console\Commands;

use App\Mail\ClientMessage;
use App\Services\InsuranceService;
use App\Services\MailSettings;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Rappel par email 15 jours, 5 jours, puis le jour de l'échéance de l'assurance décennale. */
class InsuranceReminder extends Command
{
    protected $signature = 'app:insurance-reminder';

    protected $description = 'Prévient par email de l\'échéance de l\'assurance décennale';

    public function handle(InsuranceService $insurance, MailSettings $mail, Settings $settings): int
    {
        $days = $insurance->daysLeft();
        if (! in_array($days, [InsuranceService::WARNING_DAYS, InsuranceService::DANGER_DAYS, 0], true)) {
            $this->info('Aucun rappel aujourd\'hui.');

            return self::SUCCESS;
        }

        app(PushService::class)->send('Assurance décennale', (string) $insurance->message(), route('settings.insurance'));

        if (! $mail->isConfigured()) {
            $this->warn('Envoi des emails non configuré : rappel visible seulement sur l\'accueil.');

            return self::SUCCESS;
        }

        $mail->apply();
        try {
            Mail::to($settings->get('company.email'))->send(new ClientMessage(
                'Rappel : assurance décennale',
                "Bonjour,\n\n".$insurance->message()."\n\nMettez-la à jour dans Réglages → Assurance : elle est imprimée sur vos devis et factures.",
            ));
        } catch (Throwable $e) {
            $this->error('Échec de l\'envoi : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Rappel envoyé.');

        return self::SUCCESS;
    }
}
