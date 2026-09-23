<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Search
{
    /** « Hélène DUPONT » → « helene dupont ». */
    public static function normalize(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii((string) $value))));
    }

    /**
     * Construit le texte indexé. Les numéros de téléphone sont aussi indexés
     * en chiffres seuls (« 0612345678 ») et au format national si saisis en +33.
     *
     * @param  list<string|null>  $values
     */
    public static function index(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = self::normalize($value);

            $digits = preg_replace('/\D/', '', $value);
            if (strlen($digits) >= 6 && strlen($digits) >= strlen(preg_replace('/\s/', '', $value)) - 3) {
                $parts[] = $digits;
                if (str_starts_with($digits, '33') && strlen($digits) === 11) {
                    $parts[] = '0'.substr($digits, 2);
                }
            }
        }

        return implode(' | ', array_unique($parts));
    }

    /**
     * Découpe la saisie en mots à trouver (tous doivent correspondre). Une
     * saisie qui ressemble à un numéro de téléphone devient un seul terme.
     *
     * @return list<string>
     */
    public static function terms(?string $input): array
    {
        $input = trim((string) $input);
        if ($input === '') {
            return [];
        }

        if (preg_match('/^[\d\s.+\-]{4,}$/', $input)) {
            $digits = preg_replace('/\D/', '', $input);
            if (str_starts_with($digits, '33') && strlen($digits) === 11) {
                $digits = '0'.substr($digits, 2);
            }

            return [$digits];
        }

        // Les caractères spéciaux de LIKE sont retirés ; un mot vide ne filtrerait rien.
        $terms = array_map(fn ($t) => str_replace(['%', '_', '\\'], '', $t), explode(' ', self::normalize($input)));

        return array_values(array_filter($terms, fn ($t) => $t !== ''));
    }
}
