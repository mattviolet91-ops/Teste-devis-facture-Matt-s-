<?php

namespace App\Console\Commands;

use App\Services\MoneyReportService;
use Illuminate\Console\Command;

/**
 * Chaque lundi matin : espace Argent mis à jour depuis le logiciel de devis
 * (paiements et frais), puis bilan de la semaine passée et notification.
 */
class MoneyWeekly extends Command
{
    protected $signature = 'app:argent-semaine';

    protected $description = 'Met à jour l\'espace Argent depuis les devis et fait le bilan de la semaine';

    public function handle(MoneyReportService $reports): int
    {
        $report = $reports->weekly();
        $this->info($report ? 'Bilan de la semaine du '.$report->week_start->format('d/m/Y').' enregistré.' : 'Espace Argent pas encore ouvert.');

        return self::SUCCESS;
    }
}
