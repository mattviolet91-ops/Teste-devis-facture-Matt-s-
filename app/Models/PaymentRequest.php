<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Montant libre à encaisser par carte : lien envoyé au client, facture créée
 * (acompte ou simple) au moment où le paiement est confirmé.
 */
class PaymentRequest extends Model
{
    public const KINDS = ['deposit' => 'Facture d\'acompte', 'standard' => 'Facture'];

    public const STATUSES = ['pending' => 'En attente', 'paid' => 'Payé', 'cancelled' => 'Annulé'];

    protected $fillable = ['label'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'vat_rate' => 'integer', 'paid_at' => 'datetime', 'viewed_at' => 'datetime', 'reminded_at' => 'datetime', 'reminder_count' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(OnlinePayment::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function publicUrl(): string
    {
        return rtrim((string) config('entreprise.client_url'), '/').'/p/'.$this->token;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->invoice_kind] ?? 'Facture';
    }
}
