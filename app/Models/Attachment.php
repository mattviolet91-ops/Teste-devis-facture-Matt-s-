<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Document rangé sur une fiche client. */
class Attachment extends Model
{
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', ' ').' Mo'
            : max(1, (int) round($this->size / 1024)).' Ko';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
