<?php

namespace App\Console\Commands;

use App\Http\Controllers\TrashController;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Worksite;
use Illuminate\Console\Command;

class PurgeTrash extends Command
{
    protected $signature = 'app:purge-trash';

    protected $description = 'Supprime définitivement les éléments de la corbeille de plus de 30 jours';

    public function handle(): int
    {
        $limit = now()->subDays(TrashController::RETENTION_DAYS);

        $quotes = Quote::onlyTrashed()->where('deleted_at', '<', $limit)->get()
            ->each(fn (Quote $quote) => $quote->lines()->delete())->each->forceDelete()->count();
        $invoices = Invoice::onlyTrashed()->where('deleted_at', '<', $limit)->get()
            ->each(fn (Invoice $invoice) => $invoice->lines()->delete())->each->forceDelete()->count();
        $worksites = Worksite::onlyTrashed()->where('deleted_at', '<', $limit)->get()->each->forceDelete()->count();
        $clients = Client::onlyTrashed()->where('deleted_at', '<', $limit)->get()->each->forceDelete()->count();

        $this->info("Corbeille vidée : {$clients} client(s), {$worksites} chantier(s), {$quotes} brouillon(s) de devis, {$invoices} brouillon(s) de facture.");

        return self::SUCCESS;
    }
}
