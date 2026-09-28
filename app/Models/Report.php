<?php

namespace App\Models;

use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

/**
 * Rapport d'intervention : constat, travaux réalisés, préconisations et photos.
 * Utile au client pour son assurance habitation après une urgence ou une recherche de fuite.
 */
class Report extends Model
{
    use TracksCreator;

    protected $fillable = ['client_id', 'worksite_id', 'intervention_id', 'title', 'visit_date', 'findings', 'work_done', 'recommendations'];

    protected function casts(): array
    {
        return ['visit_date' => 'date', 'sent_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class)->withTrashed();
    }

    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class);
    }

    /** Photos imprimées dans le rapport (même table que les annexes des devis et factures). */
    public function photos(): MorphToMany
    {
        return $this->morphToMany(Photo::class, 'document', 'document_photo')->withPivot('position')->orderByPivot('position');
    }

    /** Le PDF est refait à chaque ouverture : les photos restent modifiables. */
    public function photosEditable(): bool
    {
        return true;
    }

    public function isDraft(): bool
    {
        return false;
    }

    /** Lien à envoyer au client (PDF du rapport, sans connexion). */
    public function publicUrl(): string
    {
        if (! $this->public_token) {
            $this->forceFill(['public_token' => Str::random(48)])->saveQuietly();
        }

        return rtrim((string) config('entreprise.client_url'), '/').'/r/'.$this->public_token;
    }

    public function address(): ?string
    {
        return $this->worksite?->fullAddress() ?? $this->client?->fullAddress();
    }
}
