<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande d'avis Google faite (ou à faire) à un client après un chantier payé. */
class ReviewRequest extends Model
{
    public const CHANNELS = ['email' => 'email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'copy' => 'message copié'];

    protected $fillable = ['client_id', 'invoice_id', 'status', 'channel', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? '—';
    }
}
