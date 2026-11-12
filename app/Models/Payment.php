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

    protected $fillable = ['paid_at', 'amount', 'label', 'method', 'method_detail', 'reference', 'notes'];

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

    /** Encaissé sans facture (montant libre) : à rattacher à une facture existante. */
    public function scopeUnallocated(Builder $query): Builder
    {
        return $query->whereNull('invoice_id');
    }

    public function isUnallocated(): bool
    {
        return $this->invoice_id === null;
    }

    /** Paiements des factures en vigueur (une facture supprimée ne compte plus dans l'encaissé) et encaissements à rattacher. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('invoice_id')
            ->orWhereHas('invoice', fn (Builder $i) => $i->where('status', '!=', 'cancelled')));
    }

    public function methodLabel(): string
    {
        return $this->method === 'autre' && $this->method_detail
            ? $this->method_detail
            : (self::METHODS[$this->method] ?? $this->method);
    }
}
