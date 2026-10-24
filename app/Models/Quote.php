<?php

namespace App\Models;

use App\Models\Concerns\Searchable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Quote extends Model
{
    use Searchable, SoftDeletes, TracksCreator;

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
        'work_start', 'work_duration', 'waste_estimate', 'show_bank', 'payment_terms', 'notes', 'internal_notes', 'urgent_repair',
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
            'signed_on_site' => 'boolean',
            'urgent_repair' => 'boolean',
            'urgent_confirmed_at' => 'datetime',
            'urgent_confirmed_on_site' => 'boolean',
            'follow_up_count' => 'integer',
            'last_follow_up_at' => 'datetime',
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

    /** Chantier (frais, et autres devis ou factures du même chantier). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class)->orderBy('starts_on');
    }

    public function photos(): MorphToMany
    {
        return $this->morphToMany(Photo::class, 'document', 'document_photo')->withPivot('position')->orderByPivot('position');
    }

    /** PDF figé au moment de l'envoi. */
    public function snapshot(): MorphOne
    {
        return $this->morphOne(Snapshot::class, 'document')->latestOfMany();
    }

    /** Toutes les versions figées du PDF (la première est celle de l'envoi). */
    public function snapshots(): MorphMany
    {
        return $this->morphMany(Snapshot::class, 'document');
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

    /**
     * Devis signé chez un particulier (signature sur place, dans l'application) : la loi
     * interdit de recevoir un paiement avant 7 jours (art. L221-10 du Code de la
     * consommation). Le jour de la signature ne compte pas : paiement possible à partir
     * du 8e jour. Sans objet pour une signature en ligne (contrat à distance) ou une
     * réparation urgente demandée par le client.
     */
    public function paymentAllowedFrom(): ?Carbon
    {
        if (! $this->signed_at || ! $this->signed_on_site || $this->urgent_repair || ! $this->client?->isIndividual()) {
            return null;
        }

        return $this->signed_at->copy()->startOfDay()->addDays(8);
    }

    /**
     * Devis signé chez le client, sans urgence, dans le délai de 7 jours : le client
     * peut encore confirmer qu'il a demandé une intervention urgente.
     */
    /**
     * Devis accepté : ce que le client a déjà réglé et ce qu'il lui reste à payer, toutes
     * factures du devis comprises (acompte, situations, solde). Les factures annulées ne
     * comptent pas (leurs règlements passent sur la facture corrigée).
     *
     * @return array{total: int, invoiced: int, paid: int, deposits: Collection<int, Invoice>, deposits_paid: int, other_paid: int, remaining: int, open: Collection<int, Invoice>}
     */
    public function paymentSummary(): array
    {
        // Factures de ce devis et de ses versions précédentes (V1 → V2 : l'acompte suit).
        $ids = [$this->id];
        for ($previous = $this->replaces; $previous && ! in_array($previous->id, $ids, true); $previous = $previous->replaces) {
            $ids[] = $previous->id;
        }
        $invoices = Invoice::query()->whereIn('quote_id', $ids)->where('kind', '!=', 'credit')
            ->whereIn('status', Invoice::ISSUED)->orderBy('id')->get();
        $invoiced = (int) $invoices->sum('total_ttc');
        $paid = (int) $invoices->sum('amount_paid');
        $deposits = $invoices->where('kind', 'deposit')->filter(fn (Invoice $invoice) => $invoice->amount_paid > 0)->values();

        return [
            'total' => (int) $this->total_ttc,
            'invoiced' => $invoiced,
            'paid' => $paid,
            // Acomptes reçus, détaillés ; le reste des règlements (situations, solde…) à part.
            'deposits' => $deposits,
            'deposits_paid' => (int) $deposits->sum('amount_paid'),
            'other_paid' => $paid - (int) $deposits->sum('amount_paid'),
            // Base : le devis, ou plus si des travaux en plus ont été facturés.
            'remaining' => max(0, max((int) $this->total_ttc, $invoiced) - $paid),
            'open' => $invoices->filter(fn (Invoice $invoice) => $invoice->acceptsPayments() && $invoice->balance() > 0)->values(),
        ];
    }

    public function canConfirmUrgent(): bool
    {
        return ($from = $this->paymentAllowedFrom()) !== null && $from->isFuture() && in_array($this->status, ['accepted'], true);
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
