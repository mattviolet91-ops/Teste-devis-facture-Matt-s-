<?php

namespace App\Support;

/**
 * Quantités stockées en millièmes (1,5 → 1500) : aucun arrondi flottant.
 */
final class Quantity
{
    /** « 1,5 » ou « 12.25 » → 1500 / 12250. Retourne null si la saisie est invalide. */
    public static function parse(string|int|float|null $input): ?int
    {
        if ($input === null || $input === '') {
            return null;
        }

        $value = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], (string) $input);
        if (! preg_match('/^\d+(\.\d{1,3})?$/', $value)) {
            return null;
        }

        [$units, $decimals] = array_pad(explode('.', $value), 2, '');

        return (int) $units * 1000 + (int) str_pad($decimals, 3, '0');
    }

    /** 1500 → « 1,5 » ; 177000 → « 177 ». */
    public static function format(int $milli): string
    {
        $formatted = number_format($milli / 1000, 3, ',', "\u{202F}");

        return rtrim(rtrim($formatted, '0'), ',');
    }

    /** Valeur pour un champ de saisie : 1500 → « 1.5 ». */
    public static function input(int $milli): string
    {
        return rtrim(rtrim(number_format($milli / 1000, 3, '.', ''), '0'), '.');
    }
}
