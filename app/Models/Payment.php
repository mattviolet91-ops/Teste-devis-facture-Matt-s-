<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const METHODS = [
        'virement' => 'Virement',
        'cheque' => 'Chèque',
        'especes' => 'Espèces',
        'cb' => 'Carte bancaire',
        'mypos' => 'myPOS',
        'autre' => 'Autre',
    ];

    protected $fillable = ['paid_at', 'amount', 'method', 'method_detail', 'reference', 'notes'];

    protected function casts(): array
    {
        return ['paid_at' => 'date', 'amount' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** Paiements des factures en vigueur (une facture supprimée ne compte plus dans l'encaissé). */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereHas('invoice', fn (Builder $q) => $q->where('status', '!=', 'cancelled'));
    }

    public function methodLabel(): string
    {
        return $this->method === 'autre' && $this->method_detail
            ? $this->method_detail
            : (self::METHODS[$this->method] ?? $this->method);
    }
}
