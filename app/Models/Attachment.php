<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Document rangé sur une fiche client, et éventuellement sur un de ses devis ou une de ses factures. */
class Attachment extends Model
{
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'signature_requested_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'reminded_at' => 'datetime',
            'reminder_count' => 'integer',
            'signed_on_site' => 'boolean',
        ];
    }

    /** Seuls un PDF ou une photo peuvent être signés (le résultat est un PDF). */
    public function canBeSigned(): bool
    {
        return $this->mime === 'application/pdf' || in_array($this->mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    /** Signature demandée et pas encore donnée. */
    public function awaitsSignature(): bool
    {
        return $this->signature_requested_at !== null && ! $this->isSigned();
    }

    /** Lien envoyé au client (adresse client, comme les devis et factures). */
    public function publicUrl(): string
    {
        return rtrim((string) config('entreprise.client_url'), '/').'/doc/'.$this->public_token;
    }

    /** Fichier à montrer : la version signée dès qu'elle existe. */
    public function currentPath(): string
    {
        return $this->signed_path ?: $this->path;
    }

    public function currentMime(): string
    {
        return $this->signed_path ? 'application/pdf' : $this->mime;
    }

    /** Nom du fichier montré : « …-signe.pdf » pour la version signée. */
    public function currentName(): string
    {
        return $this->signed_path ? preg_replace('/\.[a-z0-9]{2,5}$/i', '', $this->name).'-signé.pdf' : $this->name;
    }

    /** Ce à quoi le document est rattaché, en clair. */
    public function attachedToLabel(): string
    {
        return match (true) {
            $this->quote_id !== null => 'devis '.($this->quote?->number ?? '(brouillon)'),
            $this->invoice_id !== null => mb_strtolower((string) $this->invoice?->kindLabel()).' '.($this->invoice?->number ?? '(brouillon)'),
            default => 'fiche client',
        };
    }

    /** Page de gestion où le document est rangé. */
    public function adminRoute(): array
    {
        return match (true) {
            $this->quote_id !== null => ['quotes.show', $this->quote_id],
            $this->invoice_id !== null => ['invoices.show', $this->invoice_id],
            default => ['clients.show', $this->client_id],
        };
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', ' ').' Mo'
            : max(1, (int) round($this->size / 1024)).' Ko';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
