<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tentative de paiement par carte (myPOS Checkout). */
class OnlinePayment extends Model
{
    protected $fillable = ['invoice_id', 'order_id', 'amount', 'status', 'test'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'test' => 'boolean'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
