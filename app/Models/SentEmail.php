<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SentEmail extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['to', 'cc', 'subject', 'body', 'attachment', 'status', 'error', 'client_id', 'sent_by'];

    public function document(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }
}
