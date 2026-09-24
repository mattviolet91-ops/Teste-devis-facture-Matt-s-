<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Devis ou facture émis avec Wix, conservé en archive (PDF d'origine). */
class WixArchive extends Model
{
    public const KINDS = ['devis' => 'Devis', 'facture' => 'Facture', 'avoir' => 'Avoir'];

    protected $fillable = ['kind', 'number', 'title', 'issue_date', 'total', 'status', 'path', 'original_name'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'total' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function label(): string
    {
        return (self::KINDS[$this->kind] ?? 'Document').' Wix n° '.$this->number;
    }
}
