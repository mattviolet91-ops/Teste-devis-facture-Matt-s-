<?php

namespace App\Services;

use App\Models\NumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Attribution des numéros de documents (DEV-2026-0001, FAC-2026-0001…).
 *
 * Le compteur ne repart jamais à zéro : seule l'année affichée change. La
 * ligne du compteur est verrouillée pendant l'attribution pour qu'aucun
 * numéro ne puisse être donné deux fois.
 */
class NumberGenerator
{
    public function next(string $type): string
    {
        return DB::transaction(function () use ($type) {
            $sequence = NumberSequence::query()->where('type', $type)->lockForUpdate()->firstOrFail();

            $number = $sequence->next_number;
            $sequence->increment('next_number');

            return $sequence->format($number);
        });
    }

    /** Aperçu du prochain numéro, sans le consommer. */
    public function preview(string $type): string
    {
        $sequence = NumberSequence::query()->where('type', $type)->firstOrFail();

        return $sequence->format($sequence->next_number);
    }
}
