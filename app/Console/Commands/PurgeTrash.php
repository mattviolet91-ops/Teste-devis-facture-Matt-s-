<?php

namespace App\Console\Commands;

use App\Http\Controllers\TrashController;
use App\Models\Client;
use App\Models\Worksite;
use Illuminate\Console\Command;

class PurgeTrash extends Command
{
    protected $signature = 'app:purge-trash';

    protected $description = 'Supprime définitivement les éléments de la corbeille de plus de 30 jours';

    public function handle(): int
    {
        $limit = now()->subDays(TrashController::RETENTION_DAYS);

        $worksites = Worksite::onlyTrashed()->where('deleted_at', '<', $limit)->get()->each->forceDelete()->count();
        $clients = Client::onlyTrashed()->where('deleted_at', '<', $limit)->get()->each->forceDelete()->count();

        $this->info("Corbeille vidée : {$clients} client(s), {$worksites} chantier(s).");

        return self::SUCCESS;
    }
}
