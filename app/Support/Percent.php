<?php

namespace App\Support;

/**
 * Pourcentages stockés en centièmes de % (10 % = 1000, 5,5 % = 550).
 */
final class Percent
{
    public static function parse(string|int|float|null $input): ?int
    {
        if ($input === null || $input === '') {
            return null;
        }

        $value = str_replace([',', ' ', '%'], ['.', '', ''], (string) $input);
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }

        [$units, $decimals] = array_pad(explode('.', $value), 2, '');

        return (int) $units * 100 + (int) str_pad($decimals, 2, '0');
    }

    /** 550 → « 5,5 % ». */
    public static function format(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',').' %';
    }

    /** 550 → « 5.5 » (champ de saisie). */
    public static function input(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.');
    }
}
