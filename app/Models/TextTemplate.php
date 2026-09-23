<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TextTemplate extends Model
{
    public const TYPES = [
        'payment_terms' => 'Conditions de paiement',
        'note' => 'Notes et informations',
        'step' => 'Étapes types',
    ];

    protected $fillable = ['type', 'label', 'body', 'is_default', 'position'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type)->orderBy('position')->orderBy('id');
    }
}
