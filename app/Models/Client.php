<?php

namespace App\Models;

use App\Models\Concerns\DescribesChanges;
use App\Models\Concerns\Searchable;
use App\Support\Phone;
use App\Support\Search;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use DescribesChanges, HasFactory, Searchable, SoftDeletes;

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
        'type', 'status', 'civility', 'first_name', 'last_name', 'company_name', 'siret',
        'email', 'phone', 'phone_2', 'address', 'postal_code', 'city',
        'source', 'source_detail', 'notes',
    ];

    public function worksites(): HasMany
    {
        return $this->hasMany(Worksite::class)->orderBy('id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->latest('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->latest('id');
    }

    /**
     * Recherche sur la fiche et sur ses chantiers : chaque mot doit se trouver
     * dans l'un ou l'autre (« dupont massy » trouve Mme Dupont dont le chantier est à Massy).
     */
    public function scopeSearchWithWorksites(Builder $query, ?string $terms): Builder
    {
        foreach (Search::terms($terms) as $term) {
            $query->where(fn (Builder $q) => $q
                ->where('clients.search_index', 'like', '%'.$term.'%')
                ->orWhereHas('worksites', fn (Builder $w) => $w->where('worksites.search_index', 'like', '%'.$term.'%')));
        }

        return $query;
    }

    /** Tri alphabétique sur le nom affiché (société ou nom de famille). */
    public function scopeAlphabetical(Builder $query): Builder
    {
        return $query->orderByRaw("LOWER(COALESCE(NULLIF(company_name, ''), last_name, first_name, '')) asc")->orderBy('first_name');
    }

    /**
     * Fiches existantes avec le même téléphone ou le même email.
     *
     * @return Collection<int, Client>
     */
    public static function findDuplicates(?string $phone, ?string $email, ?int $ignoreId = null): Collection
    {
        $digits = $phone ? preg_replace('/\D/', '', (string) Phone::format($phone)) : '';
        $email = $email ? mb_strtolower(trim($email)) : '';

        if (strlen($digits) < 6 && $email === '') {
            return collect();
        }

        return static::query()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where(function (Builder $q) use ($digits, $email) {
                if (strlen($digits) >= 6) {
                    $q->orWhere('search_index', 'like', '%'.$digits.'%');
                }
                if ($email !== '') {
                    $q->orWhere('email', $email);
                }
            })
            ->limit(5)
            ->get()
            // Le téléphone doit correspondre exactement (pas seulement une partie du numéro).
            ->filter(fn (Client $c) => ($email !== '' && $c->email === $email)
                || (strlen($digits) >= 6 && in_array($digits, [preg_replace('/\D/', '', (string) $c->phone), preg_replace('/\D/', '', (string) $c->phone_2)], true)))
            ->values();
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

    protected function fieldLabels(): array
    {
        return [
            'type' => 'Type', 'status' => 'Statut', 'civility' => 'Civilité', 'first_name' => 'Prénom',
            'last_name' => 'Nom', 'company_name' => 'Société', 'email' => 'Email', 'phone' => 'Téléphone',
            'phone_2' => 'Autre téléphone', 'address' => 'Adresse', 'postal_code' => 'Code postal', 'city' => 'Ville',
            'source' => 'Provenance', 'source_detail' => 'Précision provenance', 'notes' => 'Notes',
        ];
    }

    protected function displayValue(string $field, mixed $value): string
    {
        $value = match ($field) {
            'type' => self::TYPES[$value] ?? $value,
            'status' => self::STATUSES[$value] ?? $value,
            'source' => self::SOURCES[$value] ?? $value,
            default => $value,
        };

        return $value === null || $value === '' ? '—' : (string) $value;
    }

    protected function searchableValues(): array
    {
        return [
            $this->company_name, $this->first_name, $this->last_name, $this->email,
            $this->phone, $this->phone_2, $this->address, $this->postal_code, $this->city, $this->notes,
        ];
    }
}
