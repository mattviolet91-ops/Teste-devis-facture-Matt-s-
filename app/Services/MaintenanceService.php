<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Invoice;
use App\Models\MaintenanceReminder;
use Illuminate\Support\Str;

/**
 * Crée les rappels d'entretien à partir des factures : chaque prestation de la
 * bibliothèque qui a un délai d'entretien donne un rappel à « date + délai ».
 */
class MaintenanceService
{
    /** Factures qui correspondent à des travaux réalisés. */
    public const KINDS = ['standard', 'final'];

    public function fromInvoice(Invoice $invoice): int
    {
        if (! in_array($invoice->kind, self::KINDS, true) || ! $invoice->issue_date || ! $invoice->client_id
            || ! in_array($invoice->status, Invoice::ISSUED, true)) {
            return 0;
        }

        $items = CatalogItem::query()->whereNotNull('maintenance_months')->where('maintenance_months', '>', 0)->get();
        if ($items->isEmpty()) {
            return 0;
        }
        $byName = $items->keyBy(fn (CatalogItem $item) => $this->key($item->name));

        // Une facture de solde reprend souvent les lignes du devis : on remonte au devis s'il existe.
        $lines = $invoice->lines()->where('type', 'item')->get();
        if ($invoice->kind === 'final' && $invoice->quote) {
            $lines = $invoice->quote->lines()->where('type', 'item')->get();
        }

        $created = 0;
        foreach ($lines as $line) {
            $item = ($line->catalog_item_id ? $items->firstWhere('id', $line->catalog_item_id) : null)
                ?? $byName->get($this->key((string) $line->title));
            if (! $item) {
                continue;
            }

            $reminder = MaintenanceReminder::query()->firstOrCreate(
                ['invoice_id' => $invoice->id, 'catalog_item_id' => $item->id],
                [
                    'client_id' => $invoice->client_id,
                    'worksite_id' => $invoice->worksite_id,
                    'label' => $item->name,
                    'done_on' => $invoice->issue_date,
                    'due_on' => $invoice->issue_date->copy()->addMonthsNoOverflow($item->maintenance_months),
                ],
            );
            $created += (int) $reminder->wasRecentlyCreated;
        }

        return $created;
    }

    /** Parcourt toutes les factures déjà émises (rattrapage). */
    public function scanAll(): int
    {
        $count = 0;
        Invoice::query()->whereIn('kind', self::KINDS)->whereIn('status', Invoice::ISSUED)
            ->with('quote')->orderBy('id')
            ->each(function (Invoice $invoice) use (&$count) {
                $count += $this->fromInvoice($invoice);
            });

        return $count;
    }

    /** Facture supprimée (annulée par avoir) : ses rappels non traités disparaissent. */
    public function forgetInvoice(Invoice $invoice): void
    {
        MaintenanceReminder::query()->where('invoice_id', $invoice->id)->where('status', 'pending')->delete();
    }

    private function key(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->toString();
    }
}
