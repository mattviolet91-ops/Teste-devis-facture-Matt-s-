<?php

namespace App\Console\Commands;

use App\Http\Controllers\TrashController;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Snapshot;
use App\Models\Worksite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeTrash extends Command
{
    protected $signature = 'app:purge-trash';

    protected $description = 'Supprime définitivement les éléments de la corbeille de plus de 30 jours';

    public function handle(): int
    {
        $limit = now()->subDays(TrashController::RETENTION_DAYS);

        $quotes = Quote::onlyTrashed()->where('deleted_at', '<', $limit)->get()
            ->each(function (Quote $quote) {
                // Fichiers liés (PDF figés, signature) supprimés avec le devis.
                Storage::disk('local')->delete(array_filter(array_merge(
                    Snapshot::query()->where('document_type', $quote->getMorphClass())->where('document_id', $quote->id)->pluck('path')->all(),
                    [$quote->signature_path],
                )));
                Snapshot::query()->where('document_type', $quote->getMorphClass())->where('document_id', $quote->id)->delete();
                $quote->photos()->detach();
                $quote->lines()->delete();
            })->each->forceDelete()->count();
        $invoices = Invoice::onlyTrashed()->where('deleted_at', '<', $limit)->get()
            ->each(fn (Invoice $invoice) => $invoice->lines()->delete())->each->forceDelete()->count();
        $worksites = Worksite::onlyTrashed()->where('deleted_at', '<', $limit)->get()->each->forceDelete()->count();
        $clients = Client::onlyTrashed()->where('deleted_at', '<', $limit)->get()->each->forceDelete()->count();

        $this->info("Corbeille vidée : {$clients} client(s), {$worksites} chantier(s), {$quotes} devis, {$invoices} brouillon(s) de facture.");

        return self::SUCCESS;
    }
}
