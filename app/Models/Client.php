<?php

namespace App\Models;

use App\Models\Concerns\Searchable;
use App\Support\Phone;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, Searchable, SoftDeletes;

    public const TYPES = [
        'particulier' => 'Particulier',
        'entreprise' => 'Entreprise',
        'syndic' => 'Syndic',
        'agence' => 'Agence immobilière',
        'collectivite' => 'Collectivité',
        'autre' => 'Autre',
    ];

    public const STATUSES = [
        'prospect' => 'Prospect',
        'client' => 'Client',
    ];

    public const CIVILITIES = ['M.', 'Mme', 'M. et Mme'];

    public const SOURCES = [
        'site' => 'Site internet',
        'google' => 'Google',
        'bouche_a_oreille' => 'Bouche-à-oreille',
        'recommandation' => 'Recommandation',
        'reseaux' => 'Réseaux sociaux',
        'passage' => 'Passage / véhicule',
        'autre' => 'Autre',
    ];

    protected $fillable = [
        'type', 'status', 'civility', 'first_name', 'last_name', 'company_name',
        'email', 'phone', 'phone_2', 'address', 'postal_code', 'city',
        'source', 'source_detail', 'notes',
    ];

    public function worksites(): HasMany
    {
        return $this->hasMany(Worksite::class)->orderBy('id');
    }

    public function isIndividual(): bool
    {
        return $this->type === 'particulier';
    }

    /** Nom affiché : la société pour un professionnel, « Mme Hélène Dupont » pour un particulier. */
    public function displayName(): string
    {
        if (! $this->isIndividual() && $this->company_name) {
            return $this->company_name;
        }

        return trim(implode(' ', array_filter([$this->civility, $this->first_name, $this->last_name]))) ?: '(sans nom)';
    }

    /** Personne à contacter chez un professionnel. */
    public function contactName(): ?string
    {
        if ($this->isIndividual()) {
            return null;
        }

        return trim(implode(' ', array_filter([$this->civility, $this->first_name, $this->last_name]))) ?: null;
    }

    public function fullAddress(): ?string
    {
        $cityLine = trim($this->postal_code.' '.$this->city);

        return trim(implode(', ', array_filter([$this->address, $cityLine]))) ?: null;
    }

    public function initials(): string
    {
        $source = ! $this->isIndividual() && $this->company_name ? $this->company_name : trim($this->first_name.' '.$this->last_name);

        return collect(preg_split('/\s+/', $source))->filter()->take(2)
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?';
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::format($value));
    }

    protected function phone2(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::format($value));
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value ? mb_strtolower(trim($value)) : null);
    }

    protected function searchableValues(): array
    {
        return [
            $this->company_name, $this->first_name, $this->last_name, $this->email,
            $this->phone, $this->phone_2, $this->address, $this->postal_code, $this->city, $this->notes,
        ];
    }
}
