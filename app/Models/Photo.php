<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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

    /** Catégories présentées côte à côte dans le PDF (la première à gauche). */
    public const PAIRS = [
        ['avant', 'apres'],
        ['probleme', 'reparation'],
    ];

    /**
     * Associe dans l'ordre chaque photo « Avant » à une photo « Après » (et
     * « Problème » à « Réparation ») ; les autres restent seules.
     *
     * @param  Collection<int, Photo>  $photos
     * @return array{pairs: list<array{0: Photo, 1: Photo}>, others: Collection<int, Photo>}
     */
    public static function pairBeforeAfter(Collection $photos): array
    {
        $pairs = [];
        $paired = [];
        foreach (self::PAIRS as [$left, $right]) {
            $lefts = $photos->where('category', $left)->values();
            $rights = $photos->where('category', $right)->values();
            for ($i = 0; $i < min($lefts->count(), $rights->count()); $i++) {
                $pairs[] = [$lefts[$i], $rights[$i]];
                $paired[] = $lefts[$i]->id;
                $paired[] = $rights[$i]->id;
            }
        }

        return ['pairs' => $pairs, 'others' => $photos->reject(fn (Photo $p) => in_array($p->id, $paired, true))->values()];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
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
