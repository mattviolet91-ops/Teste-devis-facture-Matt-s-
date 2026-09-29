<?php

namespace App\Console\Commands;

use App\Services\WordpressStatsService;
use Illuminate\Console\Command;

/** Importe chaque matin les statistiques WordPress.com du site (si le compte est connecté). */
class SyncSiteStats extends Command
{
    protected $signature = 'app:site-stats';

    protected $description = 'Importe les statistiques WordPress.com du site internet';

    public function handle(WordpressStatsService $wpcom): int
    {
        if (! $wpcom->isConnected()) {
            $this->info('Compte WordPress.com non connecté.');

            return self::SUCCESS;
        }
        $this->info($wpcom->sync().' jour(s) importé(s).');

        return self::SUCCESS;
    }
}
