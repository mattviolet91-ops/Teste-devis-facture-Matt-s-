<?php

namespace App\Services;

/**
 * Calcul des montants d'un devis ou d'une facture, en centimes entiers.
 *
 * Règles :
 * - ligne : quantité × prix unitaire, moins la remise de ligne (arrondi au centime) ;
 * - ligne offerte : affichée mais comptée 0 ;
 * - ligne optionnelle : calculée à part, hors total (le client peut la choisir) ;
 * - remise globale (en % ou en montant) répartie sur les taux de TVA au prorata ;
 * - TVA calculée par taux sur la base remisée ; aucune TVA en franchise en base.
 */
class DocumentCalculator
{
    /**
     * @param  list<array{type?: string, quantity?: int, unit_price?: int, vat_rate?: int, discount_percent?: int, is_optional?: bool, is_offered?: bool}>  $lines
     * @return array{
     *     lines: list<int>,
     *     sections: array<int, int>,
     *     subtotal: int,
     *     discount: int,
     *     total_ht: int,
     *     vat: array<int, array{base: int, amount: int}>,
     *     total_vat: int,
     *     total_ttc: int,
     *     optional_total: int
     * }
     */
    public function calculate(array $lines, ?string $discountType = null, int $discountValue = 0, bool $franchise = false): array
    {
        $lineTotals = [];
        $sections = [];
        $bases = [];
        $subtotal = 0;
        $optionalTotal = 0;
        $currentSection = null;

        foreach ($lines as $index => $line) {
            $type = $line['type'] ?? 'item';

            if ($type === 'section') {
                $currentSection = $index;
                $sections[$index] = 0;
                $lineTotals[$index] = 0;

                continue;
            }

            if ($type !== 'item') {
                $lineTotals[$index] = 0;

                continue;
            }

            $amount = $this->lineAmount($line);
            $lineTotals[$index] = $amount;

            if (! empty($line['is_optional'])) {
                $optionalTotal += $amount;

                continue;
            }

            if (! empty($line['is_offered'])) {
                continue;
            }

            $subtotal += $amount;
            $rate = (int) ($line['vat_rate'] ?? 0);
            $bases[$rate] = ($bases[$rate] ?? 0) + $amount;

            if ($currentSection !== null) {
                $sections[$currentSection] += $amount;
            }
        }

        $discount = $this->globalDiscount($subtotal, $discountType, $discountValue);
        $discountedBases = $this->spreadDiscount($bases, $subtotal, $discount);

        $vat = [];
        $totalVat = 0;
        foreach ($discountedBases as $rate => $base) {
            $amount = $franchise ? 0 : $this->roundDiv($base * $rate, 10000);
            $vat[$rate] = ['base' => $base, 'amount' => $amount];
            $totalVat += $amount;
        }
        krsort($vat);

        $totalHt = $subtotal - $discount;

        return [
            'lines' => $lineTotals,
            'sections' => $sections,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total_ht' => $totalHt,
            'vat' => $franchise ? [] : $vat,
            'total_vat' => $totalVat,
            'total_ttc' => $totalHt + $totalVat,
            'optional_total' => $optionalTotal,
        ];
    }

    /** Montant HT d'une ligne, avant remise globale. */
    public function lineAmount(array $line): int
    {
        $gross = $this->roundDiv((int) ($line['quantity'] ?? 0) * (int) ($line['unit_price'] ?? 0), 1000);
        $discount = $this->roundDiv($gross * (int) ($line['discount_percent'] ?? 0), 10000);

        return $gross - $discount;
    }

    private function globalDiscount(int $subtotal, ?string $type, int $value): int
    {
        if ($subtotal <= 0) {
            return 0;
        }

        return match ($type) {
            'percent' => min($subtotal, $this->roundDiv($subtotal * min($value, 10000), 10000)),
            'amount' => min($subtotal, $value),
            default => 0,
        };
    }

    /**
     * Répartit la remise globale sur chaque taux au prorata de sa base ; le
     * dernier taux absorbe l'écart d'arrondi pour que la somme soit exacte.
     *
     * @param  array<int, int>  $bases
     * @return array<int, int>
     */
    private function spreadDiscount(array $bases, int $subtotal, int $discount): array
    {
        if ($discount === 0 || $subtotal === 0) {
            return $bases;
        }

        $remaining = $discount;
        $rates = array_keys($bases);
        $last = end($rates);

        foreach ($bases as $rate => $base) {
            $share = $rate === $last ? $remaining : $this->roundDiv($discount * $base, $subtotal);
            $share = min($share, $base, $remaining);
            $bases[$rate] = $base - $share;
            $remaining -= $share;
        }

        return $bases;
    }

    /** Division entière arrondie au plus proche, symétrique pour les montants négatifs (déductions). */
    private function roundDiv(int $numerator, int $denominator): int
    {
        $rounded = intdiv(abs($numerator) + intdiv($denominator, 2), $denominator);

        return $numerator < 0 ? -$rounded : $rounded;
    }
}
