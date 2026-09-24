<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function edit(Settings $settings): View
    {
        return view('settings.company', [
            'company' => $settings->group('company'),
            'bank' => $settings->group('bank'),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        // Espaces tolérés à la saisie (« 981 708 167 00011 »).
        $request->merge([
            'company' => array_merge($request->input('company', []), [
                'siret' => preg_replace('/\s+/', '', (string) $request->input('company.siret')),
                'vat_number' => strtoupper(preg_replace('/\s+/', '', (string) $request->input('company.vat_number'))) ?: null,
            ]),
        ]);

        $data = $request->validate([
            'company.trade_name' => ['required', 'string', 'max:120'],
            'company.legal_form' => ['required', 'string', 'max:30'],
            'company.slogan' => ['nullable', 'string', 'max:160'],
            'company.address' => ['required', 'string', 'max:160'],
            'company.postal_code' => ['required', 'string', 'max:10'],
            'company.city' => ['required', 'string', 'max:80'],
            'company.phone' => ['required', 'string', 'max:30'],
            'company.email' => ['required', 'email', 'max:120'],
            'company.website' => ['nullable', 'url', 'max:160'],
            'company.siret' => ['required', 'string', 'regex:/^\d{14}$/'],
            'company.ape_code' => ['nullable', 'string', 'max:10'],
            'company.vat_number' => ['nullable', 'string', 'regex:/^FR[0-9A-Z]{2}\d{9}$/'],
            'company.agreements' => ['nullable', 'string', 'max:200'],
            'company.mediator_name' => ['nullable', 'string', 'max:160'],
            'company.mediator_url' => ['nullable', 'url', 'max:200'],
            'bank.holder' => ['nullable', 'string', 'max:120'],
            'bank.iban' => ['nullable', 'string', 'max:40'],
            'bank.bic' => ['nullable', 'string', 'max:11'],
            'bank.card_link' => ['nullable', 'url:https', 'max:500'],
        ], [
            'bank.card_link.url' => 'Collez le lien de paiement complet, commençant par https://',
            'company.siret.regex' => 'Le SIRET doit comporter 14 chiffres.',
            'company.vat_number.regex' => 'Format attendu : FR suivi de 11 caractères (ex. FR12981708167).',
        ]);

        $values = collect($data)->dot()->map(fn ($v) => $v ?? '')->all();
        $values['bank.iban'] = strtoupper(preg_replace('/\s+/', '', $values['bank.iban']));
        $values['bank.bic'] = strtoupper(trim($values['bank.bic']));
        $values['bank.show_by_default'] = $request->boolean('bank.show_by_default');

        $settings->set($values);
        ActivityLogger::log('settings.company', 'Informations de l\'entreprise modifiées');

        return back()->with('status', 'Informations enregistrées.');
    }
}
