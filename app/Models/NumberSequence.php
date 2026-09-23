<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberSequence extends Model
{
    public const LABELS = [
        'quote' => 'Devis',
        'invoice' => 'Factures',
        'credit_note' => 'Avoirs',
    ];

    protected $fillable = ['type', 'prefix', 'next_number', 'last_issued_number', 'padding'];

    protected function casts(): array
    {
        return ['next_number' => 'integer', 'last_issued_number' => 'integer', 'padding' => 'integer'];
    }

    /** Plus petit « prochain numéro » autorisé : juste après le dernier numéro attribué. */
    public function minimumNextNumber(): int
    {
        return ($this->last_issued_number ?? 0) + 1;
    }

    /** Numéro tel qu'il sera attribué : DEV-2026-0001. */
    public function format(int $number, ?int $year = null): string
    {
        return sprintf('%s-%d-%s', $this->prefix, $year ?? now()->year, str_pad((string) $number, $this->padding, '0', STR_PAD_LEFT));
    }
}
