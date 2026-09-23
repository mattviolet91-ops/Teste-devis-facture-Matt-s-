<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    public const CONTEXTS = [
        'quote' => 'Devis',
        'invoice' => 'Factures',
        'any' => 'Message libre',
    ];

    protected $fillable = ['name', 'context', 'subject', 'body', 'is_default', 'position'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** Modèles proposés pour un contexte : ceux du contexte puis les messages libres. */
    public function scopeFor(Builder $query, string $context): Builder
    {
        return $query->whereIn('context', array_unique([$context, 'any']))
            ->orderByRaw('CASE WHEN context = ? THEN 0 ELSE 1 END', [$context])
            ->orderByDesc('is_default')
            ->ordered();
    }
}
