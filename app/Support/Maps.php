<?php

namespace App\Support;

/**
 * Liens vers le GPS : Apple Plans (s'ouvre directement dans l'application Plans
 * sur iPhone ; ailleurs, sur le site plans d'Apple).
 */
final class Maps
{
    /** Itinéraire en voiture jusqu'à l'adresse. */
    public static function directions(string $address): string
    {
        return 'https://maps.apple.com/?daddr='.rawurlencode(trim($address)).'&dirflg=d';
    }
}
