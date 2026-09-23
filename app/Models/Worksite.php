<?php

namespace App\Models;

use App\Models\Concerns\DescribesChanges;
use App\Models\Concerns\Searchable;
use App\Support\Phone;
use Database\Factories\WorksiteFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Worksite extends Model
{
    /** @use HasFactory<WorksiteFactory> */
    use DescribesChanges, HasFactory, Searchable, SoftDeletes;

    public const ROOF_TYPES = [
        'tuile_mecanique' => 'Tuile mécanique',
        'tuile_plate' => 'Tuile plate',
        'tuile_canal' => 'Tuile canal',
        'ardoise' => 'Ardoise',
        'zinc' => 'Zinc',
        'bac_acier' => 'Bac acier',
        'fibrociment' => 'Fibrociment',
        'shingle' => 'Shingle / bardeau',
        'toit_terrasse' => 'Toit-terrasse',
        'autre' => 'Autre',
    ];

    public const ACCESSIBILITY = [
        'echelle' => 'Échelle',
        'echafaudage' => 'Échafaudage',
        'nacelle' => 'Nacelle',
        'cordes' => 'Travail sur cordes',
        'difficile' => 'Accès difficile',
    ];

    protected $fillable = [
        'label', 'address', 'postal_code', 'city', 'contact_name', 'contact_phone', 'access_notes',
        'roof_type', 'roof_surface', 'roof_pitch', 'levels', 'accessibility', 'notes',
    ];

    protected function casts(): array
    {
        return ['roof_surface' => 'decimal:2', 'levels' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function fullAddress(): string
    {
        return $this->address.', '.$this->postal_code.' '.$this->city;
    }

    public function displayName(): string
    {
        return $this->label ?: $this->address;
    }

    /** Lien d'itinéraire (ouvre l'application de cartes du téléphone). */
    public function mapsUrl(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->fullAddress());
    }

    public function hasRoofDetails(): bool
    {
        return (bool) ($this->roof_type || $this->roof_surface || $this->roof_pitch || $this->levels || $this->accessibility);
    }

    protected function contactPhone(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::format($value));
    }

    protected function fieldLabels(): array
    {
        return [
            'label' => 'Nom du chantier', 'address' => 'Adresse', 'postal_code' => 'Code postal', 'city' => 'Ville',
            'contact_name' => 'Contact sur place', 'contact_phone' => 'Téléphone du contact', 'access_notes' => 'Accès',
            'roof_type' => 'Couverture', 'roof_surface' => 'Surface (m²)', 'roof_pitch' => 'Pente', 'levels' => 'Niveaux',
            'accessibility' => 'Accès toiture', 'notes' => 'Notes',
        ];
    }

    protected function displayValue(string $field, mixed $value): string
    {
        $value = match ($field) {
            'roof_type' => self::ROOF_TYPES[$value] ?? $value,
            'accessibility' => self::ACCESSIBILITY[$value] ?? $value,
            'roof_surface' => $value === null ? null : rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ','),
            default => $value,
        };

        return $value === null || $value === '' ? '—' : (string) $value;
    }

    protected function searchableValues(): array
    {
        return [$this->label, $this->address, $this->postal_code, $this->city, $this->contact_name, $this->contact_phone, $this->notes, $this->access_notes];
    }
}
