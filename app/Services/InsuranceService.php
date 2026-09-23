<?php

namespace App\Services;

use App\Models\InsuranceCertificate;
use Illuminate\Support\Carbon;

/** Échéance de l'assurance décennale et attestation en cours. */
class InsuranceService
{
    public const WARNING_DAYS = 15;

    public const DANGER_DAYS = 5;

    public function __construct(private readonly Settings $settings) {}

    public function validUntil(): ?Carbon
    {
        $date = $this->settings->get('insurance.valid_until');

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    /** Jours restants avant l'échéance (négatif : expirée). */
    public function daysLeft(): ?int
    {
        $until = $this->validUntil();

        return $until ? (int) today()->diffInDays($until, false) : null;
    }

    /** « ok », « warning » (15 jours), « danger » (5 jours ou expirée). */
    public function level(): string
    {
        $days = $this->daysLeft();

        return match (true) {
            $days === null => 'ok',
            $days <= self::DANGER_DAYS => 'danger',
            $days <= self::WARNING_DAYS => 'warning',
            default => 'ok',
        };
    }

    public function message(): ?string
    {
        $days = $this->daysLeft();
        if ($days === null || $this->level() === 'ok') {
            return null;
        }

        return match (true) {
            $days < 0 => 'Votre assurance décennale a expiré le '.$this->validUntil()->format('d/m/Y').'. Ajoutez la nouvelle attestation.',
            $days === 0 => 'Votre assurance décennale expire aujourd\'hui. Ajoutez la nouvelle attestation.',
            default => "Votre assurance décennale expire dans $days jour".($days > 1 ? 's' : '').' (le '.$this->validUntil()->format('d/m/Y').'). Pensez à ajouter la nouvelle attestation.',
        };
    }

    /** Dernière attestation avec un fichier (pour la joindre à un email). */
    public function currentCertificate(): ?InsuranceCertificate
    {
        return InsuranceCertificate::query()->whereNotNull('path')->latest('valid_until')->latest('id')->first();
    }
}
