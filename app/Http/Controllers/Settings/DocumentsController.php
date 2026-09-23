<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Contenu des PDF : couverture, déchets, CGV. */
class DocumentsController extends Controller
{
    public function edit(Settings $settings): View
    {
        return view('settings.documents', [
            'pdf' => $settings->group('pdf'),
            'mediator' => $settings->get('company.mediator_name'),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'pdf.waste_mention' => ['required', 'string', 'max:1000'],
            'pdf.waste_facility' => ['nullable', 'string', 'max:300'],
            'pdf.cgv' => ['nullable', 'string', 'max:20000'],
            'pdf.presentation_text' => ['nullable', 'string', 'max:5000'],
        ], [], ['pdf.waste_mention' => 'mention sur les déchets']);

        $values = collect($data)->dot()->map(fn ($v) => $v ?? '')->all();
        $values['pdf.cgv_enabled'] = $request->boolean('pdf.cgv_enabled');
        $values['pdf.cover_quotes'] = $request->boolean('pdf.cover_quotes');
        $values['pdf.cover_invoices'] = $request->boolean('pdf.cover_invoices');

        $settings->set($values);
        ActivityLogger::log('settings.documents', 'Contenu des documents PDF modifié');

        return back()->with('status', 'Réglages des documents enregistrés. Ils s\'appliquent aux prochains documents envoyés.');
    }
}
