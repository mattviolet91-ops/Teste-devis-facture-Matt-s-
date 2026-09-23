<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Attestation d'assurance décennale (historique). */
class InsuranceCertificate extends Model
{
    protected $fillable = ['insurer', 'policy_number', 'valid_from', 'valid_until', 'path', 'original_name'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date'];
    }
}
