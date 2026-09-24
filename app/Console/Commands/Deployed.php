<?php

namespace App\Console\Commands;

use App\Services\ActivityLogger;
use App\Services\PushService;
use Illuminate\Console\Command;

/** Appelé par scripts/deploy.sh après une mise à jour automatique du serveur. */
class Deployed extends Command
{
    protected $signature = 'app:deployed {message? : Dernière modification installée} {--echec : La mise à jour a échoué}';

    protected $description = 'Prévient que l\'application a été mise à jour (ou que la mise à jour a échoué)';

    public function handle(PushService $push): int
    {
        $message = trim((string) $this->argument('message'));

        if ($this->option('echec')) {
            ActivityLogger::log('app.deploy_failed', 'Échec de la mise à jour automatique');
            $push->send('Mise à jour de l\'application : échec', 'L\'ancienne version reste en place. Envoyez le fichier deploy.log à votre développeur.', route('dashboard'));

            return self::SUCCESS;
        }

        ActivityLogger::log('app.deployed', 'Application mise à jour'.($message ? ' : '.$message : ''));
        $push->send('Application mise à jour', $message ?: 'Les dernières améliorations sont installées.', route('dashboard'));

        return self::SUCCESS;
    }
}
