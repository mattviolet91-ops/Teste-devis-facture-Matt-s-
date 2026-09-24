<?php

namespace App\Console\Commands;

use App\Services\SiteFormImporter;
use Illuminate\Console\Command;
use Throwable;

/** Toutes les 5 minutes : demandes du formulaire du site reçues par email → demandes de devis. */
class ImportSiteRequests extends Command
{
    protected $signature = 'app:import-site-requests {--diagnostic : Teste la connexion à Gmail et affiche ce qui serait lu, sans rien créer}';

    protected $description = 'Lit dans Gmail les emails du formulaire du site et crée les demandes de devis';

    public function handle(SiteFormImporter $importer): int
    {
        if ($this->option('diagnostic')) {
            return $this->diagnostic($importer);
        }
        if (! $importer->isEnabled()) {
            return self::SUCCESS;
        }

        try {
            $this->info($importer->run().' demande(s) importée(s).');
        } catch (Throwable $e) {
            $this->warn('Lecture de la boîte Gmail impossible : '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    private function diagnostic(SiteFormImporter $importer): int
    {
        $this->line('Activé : '.($importer->isEnabled() ? 'oui' : 'non (case non cochée ou Gmail non configuré)'));
        $start = microtime(true);
        try {
            $messages = $importer->fetch(now()->subDays(7), 30);
        } catch (Throwable $e) {
            $this->error('Connexion à Gmail impossible ('.round(microtime(true) - $start, 1).' s) : '.get_class($e).' — '.$e->getMessage());
            if ($e->getPrevious()) {
                $this->error('Détail : '.$e->getPrevious()->getMessage());
            }

            return self::FAILURE;
        }
        $this->info(count($messages).' email(s) lu(s) en '.round(microtime(true) - $start, 1).' s.');
        foreach ($messages as $m) {
            $parsed = $importer->matches($m) ? $importer->parse($m['text']) : null;
            $this->line(($parsed !== null ? '[FORMULAIRE] ' : '             ').($m['date']?->format('d/m H:i') ?? '').' · '.$m['from'].' · '.mb_strimwidth($m['subject'], 0, 60, '…')
                .($parsed !== null ? ' → '.implode(' · ', array_filter([$parsed['first_name'] ?? null, $parsed['last_name'] ?? null, $parsed['phone'] ?? null])) : ''));
        }

        return self::SUCCESS;
    }
}
