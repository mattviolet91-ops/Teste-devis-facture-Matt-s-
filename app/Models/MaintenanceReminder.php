<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rappel d'entretien : reproposer une prestation quelques années après. */
class MaintenanceReminder extends Model
{
    public const STATUSES = [
        'pending' => 'À relancer',
        'contacted' => 'Relancé',
        'done' => 'Terminé',
        'dismissed' => 'Ignoré',
    ];

    protected $fillable = ['client_id', 'worksite_id', 'invoice_id', 'catalog_item_id', 'label', 'done_on', 'due_on', 'status'];

    protected function casts(): array
    {
        return ['done_on' => 'date', 'due_on' => 'date', 'contacted_at' => 'datetime', 'contact_count' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    /** Rappels encore ouverts (à relancer ou déjà relancés sans réponse). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'contacted']);
    }

    /** Ancienneté lisible : « 3 ans », « 18 mois ». */
    public function age(): string
    {
        $months = (int) $this->done_on->diffInMonths(today());
        if ($months >= 12 && $months % 12 < 3) {
            $years = intdiv($months, 12);

            return $years.' an'.($years > 1 ? 's' : '');
        }

        return max(1, $months).' mois';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
