<?php

namespace App\Models;

use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogItem extends Model
{
    use Searchable;

    protected $fillable = ['category_id', 'name', 'description', 'unit', 'unit_price', 'vat_rate', 'maintenance_months', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['unit_price' => 'integer', 'vat_rate' => 'integer', 'maintenance_months' => 'integer', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Données envoyées au sélecteur de prestations de l'éditeur de devis. */
    public function toPicker(int $defaultVatRate): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => (string) $this->description,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'vat_rate' => $this->vat_rate ?? $defaultVatRate,
            'category' => $this->category?->name ?? 'Sans catégorie',
        ];
    }

    protected function searchableValues(): array
    {
        return [$this->name, $this->description, $this->category?->name];
    }
}
