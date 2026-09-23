<?php

namespace App\Models\Concerns;

use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tient à jour une colonne `search_index` (texte normalisé) à chaque
 * enregistrement, pour une recherche rapide et tolérante aux accents.
 */
trait Searchable
{
    /** @return list<string|null> */
    abstract protected function searchableValues(): array;

    protected static function bootSearchable(): void
    {
        static::saving(function (self $model) {
            $model->search_index = Search::index($model->searchableValues());
        });
    }

    public function scopeSearch(Builder $query, ?string $terms): Builder
    {
        foreach (Search::terms($terms) as $term) {
            $query->where($this->qualifyColumn('search_index'), 'like', '%'.$term.'%');
        }

        return $query;
    }
}
