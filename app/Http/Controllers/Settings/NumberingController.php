<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NumberSequence;
use App\Services\ActivityLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NumberingController extends Controller
{
    public function edit(Settings $settings): View
    {
        return view('settings.numbering', [
            'sequences' => NumberSequence::query()->orderBy('id')->get(),
            'documents' => $settings->group('documents'),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $sequences = NumberSequence::query()->get()->keyBy('type');

        $rules = ['quote_validity_days' => ['required', 'integer', 'min:1', 'max:365']];
        foreach ($sequences as $type => $sequence) {
            $rules["sequences.$type.prefix"] = ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'];
            $rules["sequences.$type.next_number"] = ['required', 'integer', 'min:1', 'max:999999'];
        }

        $data = $request->validate($rules, [
            'regex' => 'Le préfixe ne peut contenir que des majuscules et des chiffres.',
        ]);

        foreach ($sequences as $type => $sequence) {
            $sequence->update($data['sequences'][$type]);
        }

        $settings->set(['documents.quote_validity_days' => (int) $data['quote_validity_days']]);
        ActivityLogger::log('settings.numbering', 'Numérotation modifiée', null, $data['sequences']);

        return back()->with('status', 'Numérotation enregistrée.');
    }
}
