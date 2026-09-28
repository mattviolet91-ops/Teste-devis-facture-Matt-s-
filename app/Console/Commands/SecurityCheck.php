<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Vérifie la configuration du serveur (à lancer dans le Terminal) :
 * cd ~/test-gestion && php artisan app:security-check
 */
class SecurityCheck extends Command
{
    protected $signature = 'app:security-check';

    protected $description = 'Vérifie la configuration de sécurité du serveur';

    private int $problems = 0;

    public function handle(BackupService $backups): int
    {
        $appUrl = (string) config('app.url');
        $clientUrl = (string) config('entreprise.client_url');
        $https = str_starts_with($appUrl, 'https://');

        $this->check(app()->environment('production'), 'Mode production (APP_ENV=production)', 'Mettez APP_ENV=production dans le fichier .env.');
        $this->check(! config('app.debug'), 'Mode debug désactivé (APP_DEBUG=false)', 'Mettez APP_DEBUG=false : sinon, une erreur affiche des informations secrètes.');
        $this->check(filled(config('app.key')), 'Clé de chiffrement présente (APP_KEY)', 'Lancez : php artisan key:generate');
        $this->check($https, 'Adresse de l\'application en https (APP_URL)', 'APP_URL doit commencer par https://');
        $this->check(str_starts_with($clientUrl, 'https://'), 'Adresse des clients en https (CLIENT_URL)', 'CLIENT_URL doit commencer par https://');
        $this->check(! $https || config('session.secure') === true, 'Cookies de connexion sécurisés (SESSION_SECURE_COOKIE=true)', 'Ajoutez SESSION_SECURE_COOKIE=true dans le fichier .env.');
        $this->check(realpath(public_path()) !== realpath(base_path()), 'Seul le dossier public est visible sur Internet', 'La racine du sous-domaine doit être le dossier public de l\'application.');

        $env = base_path('.env');
        $readableByAll = is_file($env) && (fileperms($env) & 0o004);
        $this->check(! $readableByAll, 'Fichier .env protégé (non lisible par les autres comptes)', 'Lancez : chmod 600 '.$env);

        $last = $backups->list()->sortByDesc('date')->first();
        $this->check($last && $last['date']->gt(now()->subDays(2)), 'Sauvegarde de moins de 2 jours'.($last ? ' (dernière : '.$last['date']->format('d/m/Y H:i').')' : ''),
            'Vérifiez que la tâche planifiée (cron schedule:run) tourne bien.');
        $this->check(User::query()->where('role', 'admin')->whereNull('disabled_at')->count() >= 1, 'Au moins un compte gérant actif', 'Créez un compte gérant : php artisan app:create-admin');

        $this->newLine();
        $this->problems === 0
            ? $this->info('Tout est en ordre.')
            : $this->warn($this->problems.' point(s) à corriger.');

        return $this->problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(bool $ok, string $label, string $advice): void
    {
        if ($ok) {
            $this->line('  ✓ '.$label);

            return;
        }
        $this->problems++;
        $this->line('  ✗ '.$label);
        $this->line('      → '.$advice);
    }
}
