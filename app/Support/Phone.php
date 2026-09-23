<?php

namespace App\Support;

final class Phone
{
    /** « 0612345678 » ou « +33 6 12 34 56 78 » → « 06 12 34 56 78 » ; autre format laissé tel quel. */
    public static function format(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '33') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        }

        if (strlen($digits) === 10 && $digits[0] === '0') {
            return trim(chunk_split($digits, 2, ' '));
        }

        return trim($phone);
    }

    /** Lien tel: au format international. */
    public static function href(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $digits = preg_replace('/[^\d+]/', '', $phone);

        return 'tel:'.(str_starts_with($digits, '0') && strlen($digits) === 10 ? '+33'.substr($digits, 1) : $digits);
    }
}
