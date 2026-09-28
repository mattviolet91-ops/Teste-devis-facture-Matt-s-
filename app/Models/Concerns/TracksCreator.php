<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Retient le compte qui a créé la fiche (suivi du commercial). */
trait TracksCreator
{
    protected static function bootTracksCreator(): void
    {
        static::creating(function ($model) {
            $model->created_by ??= auth()->id();
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
