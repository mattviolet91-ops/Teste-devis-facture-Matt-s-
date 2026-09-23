<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VatRate extends Model
{
    protected $fillable = ['label', 'rate', 'mention', 'is_default', 'is_active', 'position'];

    protected function casts(): array
    {
        return [
            'rate' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** Taux lisible : 1000 → « 10 % », 550 → « 5,5 % ». */
    public function percentLabel(): string
    {
        return rtrim(rtrim(number_format($this->rate / 100, 2, ',', ''), '0'), ',').' %';
    }
}
