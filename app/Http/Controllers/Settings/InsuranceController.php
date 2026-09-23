<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\InsuranceCertificate;
use App\Services\ActivityLogger;
use App\Services\InsuranceService;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Réglages → Assurance : données imprimées sur les documents, attestation et historique. */
class InsuranceController extends Controller
{
    public function edit(Settings $settings, InsuranceService $insurance): View
    {
        return view('settings.insurance', [
            'insurance' => $settings->group('insurance'),
            'certificates' => InsuranceCertificate::query()->latest('valid_until')->latest('id')->get(),
            'daysLeft' => $insurance->daysLeft(),
            'level' => $insurance->level(),
            'message' => $insurance->message(),
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
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'insurance.valid_until.after' => 'La fin de validité doit être après le début.',
            'certificate.mimes' => 'L\'attestation doit être un PDF ou une image.',
        ], [
            'insurance.insurer' => 'assureur', 'insurance.policy_number' => 'numéro de contrat',
            'insurance.valid_from' => 'début de validité', 'insurance.valid_until' => 'fin de validité',
            'insurance.activities' => 'activités couvertes', 'insurance.coverage_area' => 'couverture géographique',
        ]);

        $values = collect($data['insurance'])->mapWithKeys(fn ($v, $k) => ["insurance.$k" => $v ?? ''])->all();
        $changedPeriod = $settings->get('insurance.valid_until') !== $values['insurance.valid_until']
            || $settings->get('insurance.policy_number') !== $values['insurance.policy_number'];
        $settings->set($values);

        // Nouvelle attestation (fichier envoyé ou nouvelle période) : ajoutée à l'historique.
        if ($request->hasFile('certificate') || $changedPeriod) {
            $certificate = new InsuranceCertificate([
                'insurer' => $values['insurance.insurer'],
                'policy_number' => $values['insurance.policy_number'],
                'valid_from' => $values['insurance.valid_from'],
                'valid_until' => $values['insurance.valid_until'],
            ]);
            if ($file = $request->file('certificate')) {
                $certificate->path = $file->store('assurance', 'local');
                $certificate->original_name = Str::limit($file->getClientOriginalName(), 150, '');
            }
            $certificate->forceFill(['created_by' => auth()->id()])->save();
        }

        ActivityLogger::log('settings.insurance', 'Assurance décennale mise à jour (validité jusqu\'au '.$values['insurance.valid_until'].')');

        return back()->with('status', 'Assurance enregistrée. Elle s\'applique aux prochains documents envoyés.');
    }

    public function download(InsuranceCertificate $certificate): Response
    {
        abort_unless($certificate->path && Storage::disk('local')->exists($certificate->path), 404);

        return Storage::disk('local')->response($certificate->path, $certificate->original_name ?? 'attestation-decennale.pdf', [
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
