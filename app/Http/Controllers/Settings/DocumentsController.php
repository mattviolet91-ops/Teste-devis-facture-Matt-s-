<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Contenu des PDF : couverture, assurance décennale, déchets, CGV. */
class DocumentsController extends Controller
{
    public function edit(Settings $settings): View
    {
        return view('settings.documents', [
            'insurance' => $settings->group('insurance'),
            'pdf' => $settings->group('pdf'),
            'mediator' => $settings->get('company.mediator_name'),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'insurance.insurer' => ['required', 'string', 'max:160'],
            'insurance.insurer_address' => ['nullable', 'string', 'max:200'],
            'insurance.broker' => ['nullable', 'string', 'max:120'],
            'insurance.policy_number' => ['required', 'string', 'max:60'],
            'insurance.valid_from' => ['required', 'date'],
            'insurance.valid_until' => ['required', 'date', 'after:insurance.valid_from'],
            'insurance.activities' => ['required', 'string', 'max:300'],
            'insurance.coverage_area' => ['required', 'string', 'max:160'],
            'pdf.waste_mention' => ['required', 'string', 'max:1000'],
            'pdf.waste_facility' => ['nullable', 'string', 'max:300'],
            'pdf.cgv' => ['nullable', 'string', 'max:20000'],
            'pdf.presentation_text' => ['nullable', 'string', 'max:5000'],
        ], [
            'insurance.valid_until.after' => 'La fin de validité doit être après le début.',
        ], [
            'insurance.insurer' => 'assureur', 'insurance.policy_number' => 'numéro de contrat',
            'insurance.valid_from' => 'début de validité', 'insurance.valid_until' => 'fin de validité',
            'insurance.activities' => 'activités couvertes', 'insurance.coverage_area' => 'couverture géographique',
            'pdf.waste_mention' => 'mention sur les déchets',
        ]);

        $values = collect($data)->dot()->map(fn ($v) => $v ?? '')->all();
        $values['pdf.cgv_enabled'] = $request->boolean('pdf.cgv_enabled');
        $values['pdf.cover_quotes'] = $request->boolean('pdf.cover_quotes');
        $values['pdf.cover_invoices'] = $request->boolean('pdf.cover_invoices');

        $settings->set($values);
        ActivityLogger::log('settings.documents', 'Contenu des documents PDF modifié');

        return back()->with('status', 'Réglages des documents enregistrés. Ils s\'appliquent aux prochains documents envoyés.');
    }
}
