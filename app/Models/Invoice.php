<?php

namespace App\Models;

use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Facture ou avoir. Un avoir (kind = credit) annule une facture envoyée : il
 * a sa propre suite de numéros et ses montants sont enregistrés en positif.
 */
class Invoice extends Model
{
    use Searchable, SoftDeletes;

    public const KINDS = [
        'standard' => 'Facture',
        'deposit' => 'Facture d\'acompte',
        'progress' => 'Facture de situation',
        'final' => 'Facture de solde',
        'credit' => 'Avoir',
    ];

    public const STATUSES = [
        'draft' => 'Brouillon',
        'sent' => 'Envoyée',
        'paid' => 'Payée',
        'cancelled' => 'Annulée',
    ];

    protected $fillable = [
        'client_id', 'worksite_id', 'title', 'due_days', 'discount_type', 'discount_value', 'vat_regime',
        'payment_terms', 'notes', 'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'due_days' => 'integer',
            'percent' => 'integer',
            'discount_value' => 'integer',
            'total_ht' => 'integer',
            'total_vat' => 'integer',
            'total_ttc' => 'integer',
            'amount_paid' => 'integer',
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

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function lines(): MorphMany
    {
        return $this->morphMany(DocumentLine::class, 'document')->orderBy('position');
    }

    /** Avoir : la facture qu'il annule. */
    public function cancels(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cancels_id')->withTrashed();
    }

    /** Facture annulée : l'avoir émis. */
    public function creditNote(): HasOne
    {
        return $this->hasOne(self::class, 'cancels_id')->where('kind', 'credit');
    }

    /** Facture corrigée : la facture d'origine qu'elle remplace. */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_id')->withTrashed();
    }

    /** Facture d'origine : sa version corrigée. */
    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrects_id')->latest('id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCredit(): bool
    {
        return $this->kind === 'credit';
    }

    public function isFranchise(): bool
    {
        return $this->vat_regime === 'franchise';
    }

    /** Une facture envoyée et pas encore payée peut être corrigée ou annulée par avoir. */
    public function isCorrectable(): bool
    {
        return ! $this->isCredit() && $this->status === 'sent';
    }

    public function isOverdue(): bool
    {
        return ! $this->isCredit() && $this->status === 'sent' && $this->due_date?->lt(today());
    }

    public function balance(): int
    {
        return max(0, $this->total_ttc - $this->amount_paid);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Facture';
    }

    public function displayNumber(): string
    {
        return $this->number ?? 'Brouillon';
    }

    /** « Facture d'acompte FAC-2026-0001 », « Avoir AV-2026-0001 »… */
    public function fullTitle(): string
    {
        return $this->kindLabel().' '.($this->number ?? '(brouillon)');
    }

    public function statusLabel(): string
    {
        if ($this->isCredit()) {
            return $this->isDraft() ? 'Brouillon' : 'Émis';
        }

        return $this->isOverdue() ? 'En retard' : (self::STATUSES[$this->status] ?? $this->status);
    }

    public function scopeInvoices(Builder $query): Builder
    {
        return $query->where('kind', '!=', 'credit');
    }

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('kind', 'credit');
    }

    /** Documents numérotés (envoyés / émis), qui comptent dans le chiffre d'affaires. */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereNotNull('number');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->invoices()->where('status', 'sent')->whereDate('due_date', '<', today());
    }

    protected function searchableValues(): array
    {
        $number = $this->number;
        $client = $this->client;

        return [
            $number,
            $number ? preg_replace('/^\D+-\d{4}-/', '', $number) : null,
            $this->title,
            $this->quote?->number,
            $client?->displayName(),
            $client?->company_name,
            $client?->last_name,
            $this->worksite?->fullAddress(),
        ];
    }
}
