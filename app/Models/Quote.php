<?php

namespace App\Models;

use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use Searchable, SoftDeletes;

    public const STATUSES = [
        'draft' => 'Brouillon',
        'sent' => 'Envoyé',
        'accepted' => 'Accepté',
        'refused' => 'Refusé',
        'expired' => 'Expiré',
        'replaced' => 'Remplacé',
    ];

    protected $fillable = [
        'client_id', 'worksite_id', 'title', 'validity_days', 'discount_type', 'discount_value', 'vat_regime',
        'work_start', 'work_duration', 'payment_terms', 'notes', 'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'refused_at' => 'datetime',
            'validity_days' => 'integer',
            'discount_value' => 'integer',
            'total_ht' => 'integer',
            'total_vat' => 'integer',
            'total_ttc' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class)->withTrashed();
    }

    public function lines(): MorphMany
    {
        return $this->morphMany(DocumentLine::class, 'document')->orderBy('position');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id')->withTrashed();
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id')->withTrashed();
    }

    /** Factures établies à partir de ce devis (hors avoirs). */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->where('kind', '!=', 'credit')->orderBy('id');
    }

    /** Un devis accepté peut être facturé (acompte, situation, solde…). */
    public function isInvoiceable(): bool
    {
        return $this->status === 'accepted';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isFranchise(): bool
    {
        return $this->vat_regime === 'franchise';
    }

    /** Un devis envoyé peut recevoir une réponse du client (acceptation / refus). */
    public function awaitsAnswer(): bool
    {
        return in_array($this->status, ['sent', 'expired'], true);
    }

    public function displayNumber(): string
    {
        return $this->number ?? 'Brouillon';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Devis envoyés sans réponse et encore valables. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    protected function searchableValues(): array
    {
        $number = $this->number;
        $client = $this->client;

        return [
            $number,
            $number ? preg_replace('/^\D+-\d{4}-/', '', $number) : null,
            $this->title,
            $client?->displayName(),
            $client?->company_name,
            $client?->last_name,
            $this->worksite?->fullAddress(),
        ];
    }
}
