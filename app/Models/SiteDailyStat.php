<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Visites par jour lues dans WordPress.com (Jetpack Stats). */
class SiteDailyStat extends Model
{
    protected $fillable = ['day', 'views', 'visitors'];

    protected function casts(): array
    {
        return ['day' => 'date', 'views' => 'integer', 'visitors' => 'integer'];
    }
}
