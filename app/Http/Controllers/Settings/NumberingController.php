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

        $rules = [
            'quote_validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'invoice_due_days' => ['required', 'integer', 'min:0', 'max:120'],
            'deposit_percent' => ['required', 'integer', 'min:1', 'max:100'],
        ];
        $messages = [];
        foreach ($sequences as $type => $sequence) {
            $rules["sequences.$type.prefix"] = ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'];
            $rules["sequences.$type.next_number"] = ['required', 'integer', 'min:'.$sequence->minimumNextNumber(), 'max:999999'];
            $messages["sequences.$type.next_number.min"] = $sequence->last_issued_number
                ? "Le numéro {$sequence->last_issued_number} a déjà été attribué : le prochain numéro doit être au moins {$sequence->minimumNextNumber()} (pas de doublon possible)."
                : 'Le prochain numéro doit être au moins 1.';
        }

        $data = $request->validate($rules, $messages + [
            'regex' => 'Le préfixe ne peut contenir que des majuscules et des chiffres.',
        ]);

        foreach ($sequences as $type => $sequence) {
            $sequence->update($data['sequences'][$type]);
        }

        $settings->set([
            'documents.quote_validity_days' => (int) $data['quote_validity_days'],
            'documents.invoice_due_days' => (int) $data['invoice_due_days'],
            'documents.deposit_percent' => (int) $data['deposit_percent'],
        ]);
        ActivityLogger::log('settings.numbering', 'Numérotation modifiée', null, $data['sequences']);

        return back()->with('status', 'Numérotation enregistrée.');
    }
}
