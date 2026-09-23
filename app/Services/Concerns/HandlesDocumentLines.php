<?php

namespace App\Services\Concerns;

use App\Models\Invoice;
use App\Models\Quote;

/**
 * Lignes et totaux communs aux devis et aux factures. La classe qui l'utilise
 * fournit `$this->calculator` (DocumentCalculator).
 */
trait HandlesDocumentLines
{
    /** @param  list<array<string, mixed>>  $lines */
    protected function replaceLines(Quote|Invoice $document, array $lines): void
    {
        $document->lines()->delete();
        foreach (array_values($lines) as $position => $line) {
            $document->lines()->create($line + ['position' => $position + 1]);
        }
    }

    /** Recalcule et enregistre les totaux à partir des lignes. */
    public function recalculate(Quote|Invoice $document): Quote|Invoice
    {
        $lines = $document->lines()->get();
        $result = $this->calculator->calculate(
            $lines->map->toCalculation()->all(),
            $document->discount_type,
            (int) $document->discount_value,
            $document->isFranchise(),
        );

        foreach ($lines->values() as $index => $line) {
            $line->update(['total_ht' => $result['lines'][$index]]);
        }

        $document->forceFill([
            'total_ht' => $result['total_ht'],
            'total_vat' => $result['total_vat'],
            'total_ttc' => $result['total_ttc'],
        ])->save();

        return $document->setRelation('lines', $lines);
    }

    /** Totaux détaillés pour l'affichage (sous-totaux de sections, TVA par taux…). */
    public function breakdown(Quote|Invoice $document): array
    {
        return $this->calculator->calculate(
            $document->lines->map->toCalculation()->values()->all(),
            $document->discount_type,
            (int) $document->discount_value,
            $document->isFranchise(),
        );
    }
}
