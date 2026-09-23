<?php

namespace App\Http\Controllers\Concerns;

use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\TextTemplate;
use App\Models\Unit;
use App\Models\VatRate;
use App\Services\Settings;

/** Données de l'éditeur de lignes, communes aux devis et aux factures. */
trait ProvidesEditorData
{
    /** @return array<string, mixed> */
    protected function editorData(Quote|Invoice $document, Settings $settings): array
    {
        $defaultRate = (int) (VatRate::query()->where('is_default', true)->value('rate') ?? 0);

        return [
            'clients' => Client::query()->with('worksites:id,client_id,label,address,postal_code,city')->alphabetical()->get(),
            'units' => Unit::query()->where('is_active', true)->ordered()->pluck('label', 'code'),
            'vatRates' => VatRate::query()->where('is_active', true)->ordered()->get(),
            'defaultVatRate' => $defaultRate,
            'franchise' => old('vat_regime', $document->vat_regime ?? $settings->get('vat.regime')) === 'franchise',
            'catalog' => CatalogItem::query()->active()->with('category')->orderBy('category_id')->orderBy('position')->get()
                ->map->toPicker($defaultRate)->values(),
            'steps' => TextTemplate::query()->ofType('step')->get(),
            'noteTemplates' => TextTemplate::query()->ofType('note')->get(),
            'paymentTemplates' => TextTemplate::query()->ofType('payment_terms')->get(),
        ];
    }
}
