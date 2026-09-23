<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** PDF figé d'un document envoyé (fichier privé + empreinte SHA-256). */
class Snapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['path', 'sha256', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }
}
