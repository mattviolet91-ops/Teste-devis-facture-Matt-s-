<?php

namespace App\Support;

use App\Models\DocumentLine;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Support\Collection;

/**
 * Valeurs d'une ligne telles qu'affichées dans les champs de l'éditeur,
 * qu'elles viennent de la base ou d'un formulaire renvoyé avec erreurs.
 */
final class LineInput
{
    /** @return array<string, mixed> */
    public static function fromModel(DocumentLine $line): array
    {
        return [
            'type' => $line->type,
            'title' => $line->title,
            'description' => $line->description,
            'quantity' => Quantity::input($line->quantity ?: 1000),
            'unit' => $line->unit,
            'unit_price' => self::money($line->unit_price),
            'vat_rate' => $line->vat_rate,
            'discount_percent' => $line->discount_percent ? Percent::input($line->discount_percent) : '',
            'is_optional' => $line->is_optional,
            'is_offered' => $line->is_offered,
            'hide_prices' => $line->hide_prices,
            'catalog_item_id' => $line->catalog_item_id,
        ];
    }

    /**
     * Lignes à afficher dans l'éditeur : celles renvoyées après une erreur de
     * saisie, sinon celles du document.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function forEditor(Quote|Invoice $document, int $defaultVatRate): Collection
    {
        $old = old('lines');
        if ($old !== null) {
            return collect($old)->map(fn ($l) => array_merge(self::blank($l['type'] ?? 'item', $defaultVatRate), $l))->values();
        }

        return $document->lines->map(fn ($line) => self::fromModel($line))->values();
    }

    /** @return array<string, mixed> */
    public static function blank(string $type, int $defaultVatRate): array
    {
        return [
            'type' => $type, 'title' => '', 'description' => '', 'quantity' => '1', 'unit' => 'forfait',
            'unit_price' => '', 'vat_rate' => $defaultVatRate, 'discount_percent' => '',
            'is_optional' => false, 'is_offered' => false, 'hide_prices' => false, 'catalog_item_id' => null,
        ];
    }

    /** 80050 → « 800,50 » (sans séparateur de milliers : plus simple à corriger au clavier). */
    public static function money(int $cents): string
    {
        return str_replace('.', ',', number_format($cents / 100, 2, '.', ''));
    }
}
