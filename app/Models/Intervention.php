<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Intervention planifiée sur un chantier (un ou plusieurs jours). */
class Intervention extends Model
{
    public const STATUSES = [
        'planned' => 'Prévue',
        'done' => 'Terminée',
        'cancelled' => 'Annulée',
    ];

    protected $fillable = ['client_id', 'worksite_id', 'quote_id', 'title', 'starts_on', 'ends_on', 'start_time', 'status', 'notes'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class)->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    /** Interventions qui touchent la période [du, au]. */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled');
    }

    public function address(): ?string
    {
        return $this->worksite?->fullAddress() ?? $this->client?->fullAddress();
    }

    public function days(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /** « lundi 12 octobre à 8h00 », « du 12 au 14 octobre ». */
    public function whenLabel(): string
    {
        $start = $this->starts_on->locale('fr');
        $time = $this->start_time ? ' à '.$this->timeLabel() : '';
        if ($this->days() === 1) {
            return $start->isoFormat('dddd D MMMM').$time;
        }

        return 'du '.$start->isoFormat('dddd D MMMM').$time.' au '.$this->ends_on->locale('fr')->isoFormat('dddd D MMMM');
    }

    /** « 8h00 » */
    public function timeLabel(): string
    {
        if (! $this->start_time) {
            return '';
        }
        [$hour, $minute] = explode(':', $this->start_time);

        return (int) $hour.'h'.$minute;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
