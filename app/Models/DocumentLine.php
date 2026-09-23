<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentLine extends Model
{
    public const TYPES = ['item', 'section', 'text'];

    protected $fillable = [
        'position', 'type', 'title', 'description', 'quantity', 'unit', 'unit_price', 'vat_rate',
        'discount_percent', 'is_optional', 'is_offered', 'hide_prices', 'total_ht', 'catalog_item_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'vat_rate' => 'integer',
            'discount_percent' => 'integer',
            'total_ht' => 'integer',
            'is_optional' => 'boolean',
            'is_offered' => 'boolean',
            'hide_prices' => 'boolean',
        ];
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    public function isItem(): bool
    {
        return $this->type === 'item';
    }

    public function isSection(): bool
    {
        return $this->type === 'section';
    }

    /** Étapes de la description, une par ligne. */
    public function steps(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->description))));
    }

    /** Valeurs utilisées par le calculateur. */
    public function toCalculation(): array
    {
        return $this->only(['type', 'quantity', 'unit_price', 'vat_rate', 'discount_percent', 'is_optional', 'is_offered']);
    }
}
