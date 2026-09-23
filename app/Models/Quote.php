<?php

namespace App\Models;

use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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
        'work_start', 'work_duration', 'waste_estimate', 'show_bank', 'payment_terms', 'notes', 'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'refused_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'change_requested_at' => 'datetime',
            'validity_days' => 'integer',
            'show_bank' => 'boolean',
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

    /** Photos imprimées en annexe du PDF. */
    public function photos(): MorphToMany
    {
        return $this->morphToMany(Photo::class, 'document', 'document_photo')->withPivot('position')->orderByPivot('position');
    }

    /** PDF figé au moment de l'envoi. */
    public function snapshot(): MorphOne
    {
        return $this->morphOne(Snapshot::class, 'document')->latestOfMany();
    }

    /** Lien client (créé au premier besoin). */
    public function publicUrl(): string
    {
        if (! $this->public_token) {
            $this->forceFill(['public_token' => Str::random(48)])->saveQuietly();
        }

        return rtrim((string) config('entreprise.client_url'), '/').'/d/'.$this->public_token;
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    /** Le client peut encore accepter en ligne. */
    public function canBeSignedOnline(): bool
    {
        return $this->status === 'sent' && ($this->valid_until === null || ! $this->valid_until->isPast() || $this->valid_until->isToday());
    }

    /** Photos du PDF modifiables : brouillon, ou devis envoyé pas encore accepté. */
    public function photosEditable(): bool
    {
        return $this->isDraft() || ($this instanceof Quote && in_array($this->status, ['sent', 'expired'], true) && ! $this->signed_at);
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
