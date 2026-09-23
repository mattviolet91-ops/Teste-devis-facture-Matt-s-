<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Passage en utilisation réelle : supprime les devis, factures, avoirs,
 * paiements et emails d'essai, et remet la numérotation à 1. Les clients,
 * chantiers, photos, documents, réglages et prestations sont conservés.
 * Une sauvegarde complète est faite avant toute suppression.
 */
class ResetDocuments extends Command
{
    protected $signature = 'app:reset-documents {--confirmer : Obligatoire : confirme la suppression des documents d\'essai}';

    protected $description = 'Supprime les devis / factures d\'essai et remet la numérotation à zéro (clients conservés)';

    public function handle(BackupService $backups): int
    {
        $counts = [
            'devis' => Quote::withTrashed()->count(),
            'factures et avoirs' => Invoice::withTrashed()->count(),
            'paiements' => DB::table('payments')->count(),
            'emails' => DB::table('sent_emails')->count(),
        ];

        $this->line('Documents d\'essai trouvés : '.collect($counts)->map(fn ($n, $label) => "$n $label")->implode(', ').'.');

        if (! $this->option('confirmer')) {
            $this->warn('Rien n\'a été supprimé. Relancez avec --confirmer pour effectuer la remise à zéro.');

            return self::SUCCESS;
        }

        $backup = $backups->create('complete');
        $this->info("Sauvegarde complète faite avant suppression : $backup");

        $files = array_merge(
            DB::table('snapshots')->pluck('path')->all(),
            Quote::withTrashed()->whereNotNull('signature_path')->pluck('signature_path')->all(),
        );

        DB::transaction(function () {
            $quoteType = (new Quote)->getMorphClass();
            $invoiceType = (new Invoice)->getMorphClass();

            DB::table('payments')->delete();
            DB::table('sent_emails')->delete();
            DB::table('snapshots')->delete();
            DB::table('document_photo')->delete();
            DB::table('document_lines')->whereIn('document_type', [$quoteType, $invoiceType])->delete();
            DB::table('activity_log')->whereIn('subject_type', [$quoteType, $invoiceType])->delete();

            // Liens entre documents d'abord, puis les documents eux-mêmes.
            DB::table('invoices')->update(['cancels_id' => null, 'corrects_id' => null, 'quote_id' => null]);
            DB::table('invoices')->delete();
            DB::table('quotes')->update(['replaces_id' => null, 'replaced_by_id' => null]);
            DB::table('quotes')->delete();

            DB::table('number_sequences')->update(['next_number' => 1, 'last_issued_number' => null]);

            // Les clients redeviennent des prospects tant qu'aucun devis n'est accepté.
            DB::table('clients')->update(['status' => 'prospect']);
        });

        Storage::disk('local')->delete($files);

        $this->info('Remise à zéro terminée : prochain devis DEV-'.now()->year.'-0001, prochaine facture FAC-'.now()->year.'-0001.');

        return self::SUCCESS;
    }
}
