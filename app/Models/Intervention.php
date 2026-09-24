<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Élément du planning : intervention sur un chantier (un ou plusieurs jours) ou rendez-vous. */
class Intervention extends Model
{
    public const KINDS = [
        'chantier' => 'Chantier',
        'rdv' => 'Rendez-vous',
    ];

    /** Objets de rendez-vous proposés. */
    public const APPOINTMENT_TITLES = [
        'Visite pour devis (métré)',
        'Présentation du devis',
        'Réception des travaux',
        'Rendez-vous fournisseur',
        'Expertise / assurance',
    ];

    public const STATUSES = [
        'planned' => 'Prévue',
        'done' => 'Terminée',
        'cancelled' => 'Annulée',
    ];

    protected $fillable = ['kind', 'client_id', 'worksite_id', 'quote_id', 'location', 'title', 'starts_on', 'ends_on', 'start_time', 'end_time', 'status', 'notes'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'reminded_at' => 'datetime'];
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

    /** Sans client, ou avec un client qui n'est pas à la corbeille. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('client_id')->orWhereHas('client'));
    }

    public function isAppointment(): bool
    {
        return $this->kind === 'rdv';
    }

    /** Nom affiché dans le planning : le client, sinon l'objet du rendez-vous. */
    public function heading(): string
    {
        return $this->client?->displayName() ?? $this->title;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled');
    }

    public function address(): ?string
    {
        return $this->location ?: ($this->worksite?->fullAddress() ?? $this->client?->fullAddress());
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
        if ($this->isAppointment() && $this->start_time && $this->end_time) {
            $time = ' de '.$this->timeLabel().' à '.$this->timeLabel($this->end_time);
        }
        if ($this->days() === 1) {
            return $start->isoFormat('dddd D MMMM').$time;
        }

        return 'du '.$start->isoFormat('dddd D MMMM').$time.' au '.$this->ends_on->locale('fr')->isoFormat('dddd D MMMM');
    }

    /** « 8h00 » */
    public function timeLabel(?string $time = null): string
    {
        $time ??= $this->start_time;
        if (! $time) {
            return '';
        }
        [$hour, $minute] = explode(':', $time);

        return (int) $hour.'h'.$minute;
    }

    /** « 9h00 – 10h00 » (rendez-vous) ou « 8h00 ». */
    public function timeRange(): string
    {
        return $this->timeLabel().($this->end_time ? ' – '.$this->timeLabel($this->end_time) : '');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
