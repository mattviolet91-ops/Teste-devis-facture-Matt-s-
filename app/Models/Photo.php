<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo extends Model
{
    public const CATEGORIES = [
        'avant' => 'Avant',
        'pendant' => 'Pendant',
        'apres' => 'Après',
        'probleme' => 'Problème',
        'reparation' => 'Réparation',
        'autre' => 'Autre',
    ];

    protected $fillable = ['category', 'caption'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'width' => 'integer', 'height' => 'integer', 'size' => 'integer'];
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class)->withTrashed();
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Autre';
    }

    /** Image affichée : la version annotée si elle existe. */
    public function displayPath(): string
    {
        return $this->annotated_path ?: $this->path;
    }
}
