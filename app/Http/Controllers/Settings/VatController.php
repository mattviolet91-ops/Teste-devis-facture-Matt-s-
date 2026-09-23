<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\VatRate;
use App\Services\ActivityLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VatController extends Controller
{
    public function edit(Settings $settings): View
    {
        return view('settings.vat', [
            'vat' => $settings->group('vat'),
            'rates' => VatRate::query()->ordered()->get(),
            'units' => Unit::query()->ordered()->get(),
        ]);
    }

    public function updateRegime(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'regime' => ['required', Rule::in(['assujetti', 'franchise'])],
            'franchise_mention' => ['required', 'string', 'max:200'],
        ]);

        $settings->set([
            'vat.regime' => $data['regime'],
            'vat.franchise_mention' => $data['franchise_mention'],
            'vat.reduced_rate_mention_enabled' => $request->boolean('reduced_rate_mention_enabled'),
        ]);
        ActivityLogger::log('settings.vat', 'Régime de TVA modifié', null, ['regime' => $data['regime']]);

        return back()->with('status', 'Régime de TVA enregistré.');
    }

    public function storeRate(Request $request): RedirectResponse
    {
        $data = $this->validateRate($request);
        $rate = VatRate::query()->create($data + ['position' => (int) VatRate::query()->max('position') + 1]);
        $this->applyDefault($rate, $request->boolean('is_default'));
        ActivityLogger::log('settings.vat_rate', "Taux de TVA ajouté : {$rate->label}", $rate);

        return back()->with('status', 'Taux ajouté.');
    }

    public function updateRate(Request $request, VatRate $rate): RedirectResponse
    {
        $rate->update($this->validateRate($request) + ['is_active' => $request->boolean('is_active')]);
        $this->applyDefault($rate, $request->boolean('is_default'));
        ActivityLogger::log('settings.vat_rate', "Taux de TVA modifié : {$rate->label}", $rate);

        return back()->with('status', 'Taux enregistré.');
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:units,code'],
            'label' => ['required', 'string', 'max:60'],
        ]);
        $unit = Unit::query()->create($data + ['position' => (int) Unit::query()->max('position') + 1]);
        ActivityLogger::log('settings.unit', "Unité ajoutée : {$unit->code}", $unit);

        return back()->with('status', 'Unité ajoutée.');
    }

    public function updateUnit(Request $request, Unit $unit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('units', 'code')->ignore($unit)],
            'label' => ['required', 'string', 'max:60'],
        ]);
        $unit->update($data + ['is_active' => $request->boolean('is_active')]);
        ActivityLogger::log('settings.unit', "Unité modifiée : {$unit->code}", $unit);

        return back()->with('status', 'Unité enregistrée.');
    }

    /** @return array<string, mixed> */
    private function validateRate(Request $request): array
    {
        $request->merge(['rate' => str_replace(',', '.', (string) $request->input('rate'))]);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'mention' => ['nullable', 'string', 'max:500'],
        ]);

        // Saisie en pourcentage (« 5,5 ») → centièmes de pour cent (550).
        $data['rate'] = (int) round((float) $data['rate'] * 100);

        return $data;
    }

    private function applyDefault(VatRate $rate, bool $isDefault): void
    {
        if (! $isDefault) {
            if ($rate->is_default) {
                $rate->update(['is_default' => false]);
            }

            return;
        }

        DB::transaction(function () use ($rate) {
            VatRate::query()->whereKeyNot($rate->getKey())->update(['is_default' => false]);
            $rate->update(['is_default' => true, 'is_active' => true]);
        });
    }
}
