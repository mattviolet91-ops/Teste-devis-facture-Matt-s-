<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande de devis reçue depuis le site internet. */
class QuoteRequest extends Model
{
    /** Travaux proposés dans le formulaire. */
    public const WORKS = [
        'demoussage' => 'Démoussage / nettoyage de toiture',
        'traitement' => 'Traitement hydrofuge',
        'fuite' => 'Recherche de fuite / réparation',
        'couverture' => 'Réfection de couverture',
        'zinguerie' => 'Gouttières / zinguerie',
        'isolation' => 'Isolation',
        'velux' => 'Fenêtre de toit',
        'autre' => 'Autre',
    ];

    protected $fillable = ['client_id', 'worksite_id', 'works', 'message', 'availability', 'status', 'ip_address'];

    protected function casts(): array
    {
        return ['works' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class)->withTrashed();
    }

    public function worksLabel(): string
    {
        return collect($this->works ?? [])->map(fn ($k) => self::WORKS[$k] ?? $k)->implode(', ');
    }
}
