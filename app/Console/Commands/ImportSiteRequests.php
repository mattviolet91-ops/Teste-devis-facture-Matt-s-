<?php

namespace App\Console\Commands;

use App\Services\SiteFormImporter;
use Illuminate\Console\Command;
use Throwable;

/** Toutes les 5 minutes : demandes du formulaire du site reçues par email → demandes de devis. */
class ImportSiteRequests extends Command
{
    protected $signature = 'app:import-site-requests';

    protected $description = 'Lit dans Gmail les emails du formulaire du site et crée les demandes de devis';

    public function handle(SiteFormImporter $importer): int
    {
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
}
