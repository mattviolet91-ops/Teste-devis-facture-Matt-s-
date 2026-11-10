<?php

namespace App\Console\Commands;

use App\Http\Controllers\TrashController;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Photo;
use App\Models\Quote;
use App\Models\SiteEvent;
use App\Models\Snapshot;
use App\Models\Worksite;
use App\Services\PhotoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PurgeTrash extends Command
{
    protected $signature = 'app:purge-trash';

    protected $description = 'Supprime définitivement les éléments de la corbeille de plus de 30 jours';

    public function __construct(private readonly PhotoService $photos)
    {
        parent::__construct();
    }

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
        $worksites = Worksite::onlyTrashed()->where('deleted_at', '<', $limit)->get()
            ->filter(fn (Worksite $worksite) => $this->purge("chantier {$worksite->id}", function () use ($worksite) {
                Photo::query()->where('worksite_id', $worksite->id)->get()->each(fn (Photo $photo) => $this->photos->delete($photo));
                $worksite->forceDelete();
            }))->count();
        $clients = Client::onlyTrashed()->where('deleted_at', '<', $limit)->get()
            ->filter(fn (Client $client) => $this->purge("client {$client->id}", fn () => $this->purgeClient($client)))->count();

        // Paiements commencés depuis le lien « montant libre » puis abandonnés (aucun client rattaché).
        PaymentRequest::query()->whereNull('client_id')->where('status', 'pending')->where('created_at', '<', now()->subDays(2))->delete();
        // Statistiques du site : conservées 13 mois au plus (recommandation de la CNIL).
        SiteEvent::query()->where('created_at', '<', now()->subMonths(13))->delete();

        $this->info("Corbeille vidée : {$clients} client(s), {$worksites} chantier(s), {$quotes} devis, {$invoices} brouillon(s) de facture.");

        return self::SUCCESS;
    }

    /**
     * Supprime un élément sans bloquer les autres : s'il est encore rattaché à quelque chose
     * (facture, devis restauré…), il reste dans la corbeille et l'incident est journalisé.
     */
    private function purge(string $label, callable $delete): bool
    {
        try {
            DB::transaction($delete);

            return true;
        } catch (Throwable $e) {
            Log::warning("Corbeille : suppression définitive impossible ($label)", ['error' => $e->getMessage()]);
            $this->warn("Conservé : $label (encore rattaché à d'autres éléments).");

            return false;
        }
    }

    /** Le client, ses liens de paiement non réglés et tous ses fichiers (documents, signatures, photos). */
    private function purgeClient(Client $client): void
    {
        $files = [];
        foreach (Attachment::query()->where('client_id', $client->id)->get() as $attachment) {
            array_push($files, $attachment->path, $attachment->signed_path, $attachment->signature_path);
        }
        foreach (Photo::query()->where('client_id', $client->id)->get() as $photo) {
            array_push($files, $photo->path, $photo->thumb_path, $photo->annotated_path);
        }
        PaymentRequest::query()->where('client_id', $client->id)->where('status', '!=', 'paid')->delete();
        $client->forceDelete();
        // Fichiers retirés seulement une fois le client réellement supprimé.
        DB::afterCommit(fn () => Storage::disk('local')->delete(array_values(array_filter($files))));
    }
}
