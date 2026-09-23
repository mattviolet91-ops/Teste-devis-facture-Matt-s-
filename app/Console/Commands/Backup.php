<?php

namespace App\Console\Commands;

use App\Mail\ClientMessage;
use App\Services\BackupService;
use App\Services\MailSettings;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sauvegarde de nuit : base chaque jour, base + fichiers le 1er du mois. Email de succès ou d'échec. */
class Backup extends Command
{
    protected $signature = 'app:backup {--type= : quotidienne, mensuelle ou complete}';

    protected $description = 'Sauvegarde la base de données (et les fichiers le 1er du mois)';

    public function handle(BackupService $backups, Settings $settings, MailSettings $mail, PushService $push): int
    {
        $type = $this->option('type') ?: (now()->day === 1 ? 'mensuelle' : 'quotidienne');

        try {
            $name = $backups->create($type);
            $pruned = $backups->prune();
        } catch (Throwable $e) {
            $this->error('Échec de la sauvegarde : '.$e->getMessage());
            $push->send('Échec de la sauvegarde', $e->getMessage(), route('settings.backups'));
            $this->mail($settings, $mail, 'Échec de la sauvegarde', "La sauvegarde de cette nuit a échoué :\n\n".$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sauvegarde créée : $name ($pruned ancienne(s) supprimée(s)).");
        $this->mail($settings, $mail, 'Sauvegarde réussie', 'La sauvegarde '.($type === 'mensuelle' ? 'mensuelle (données et fichiers)' : 'de cette nuit')." s'est bien déroulée ($name).");

        return self::SUCCESS;
    }

    private function mail(Settings $settings, MailSettings $mail, string $subject, string $text): void
    {
        if (! $mail->isConfigured() || ! $settings->get('company.email')) {
            return;
        }

        try {
            $mail->apply();
            Mail::to($settings->get('company.email'))->send(new ClientMessage($subject, "Bonjour,\n\n$text", buttonUrl: route('settings.backups'), buttonLabel: 'Voir mes sauvegardes'));
        } catch (Throwable $e) {
            $this->warn('Email non envoyé : '.$e->getMessage());
        }
    }
}
