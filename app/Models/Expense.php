<?php

namespace App\Models;

use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Frais d'une facture (matériaux, location, déchetterie…) : visibles par le gérant seul, jamais par le client. */
class Expense extends Model
{
    use TracksCreator;

    public const CATEGORIES = [
        'materiaux' => 'Matériaux',
        'location' => 'Location (échafaudage, nacelle…)',
        'dechets' => 'Déchetterie',
        'carburant' => 'Carburant, péage, parking',
        'outillage' => 'Outillage',
        'autre' => 'Autre',
    ];

    protected $fillable = ['spent_on', 'label', 'supplier', 'category', 'amount_ttc', 'vat', 'quote_id', 'invoice_id', 'project_id'];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount_ttc' => 'integer', 'vat' => 'integer'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    /** Coût réel pour l'entreprise : hors TVA récupérable. */
    public function amountHt(): int
    {
        return $this->amount_ttc - $this->vat;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
