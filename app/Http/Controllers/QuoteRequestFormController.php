<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\QuoteRequest;
use App\Services\QuoteRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/** Formulaire public « Demander un devis » (lien depuis le site internet). */
class QuoteRequestFormController extends Controller
{
    public function create(): View
    {
        // Heure d'ouverture chiffrée : un robot qui envoie en moins de 3 secondes est ignoré.
        return view('portal.request', ['started' => Crypt::encryptString((string) now()->timestamp)]);
    }

    public function store(Request $request, QuoteRequestService $requests): RedirectResponse
    {
        // Pièges à robots : champ caché rempli, ou formulaire envoyé trop vite / trop tard.
        try {
            $elapsed = now()->timestamp - (int) Crypt::decryptString((string) $request->input('started'));
        } catch (Throwable) {
            $elapsed = -1;
        }
        if ($request->filled('website') || $elapsed < 3 || $elapsed > 86400) {
            return redirect()->route('portal.request.thanks');
        }

        if ($request->filled('postal_code')) {
            $request->merge(['postal_code' => preg_replace('/\s+/', '', (string) $request->input('postal_code'))]);
        }

        $data = $request->validate([
            'civility' => ['nullable', Rule::in(Client::CIVILITIES)],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[\d\s.+()\-]{10,}$/'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:160'],
            'postal_code' => ['nullable', 'regex:/^\d{5}$/'],
            'city' => ['nullable', 'string', 'max:80'],
            'works' => ['nullable', 'array'],
            'works.*' => [Rule::in(array_keys(QuoteRequest::WORKS))],
            'message' => ['nullable', 'string', 'max:3000'],
            'availability' => ['nullable', 'string', 'max:200'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
            'consent' => ['accepted'],
        ], [
            'last_name.required' => 'Indiquez votre nom.',
            'phone.required' => 'Indiquez votre numéro de téléphone pour que nous puissions vous rappeler.',
            'phone.regex' => 'Numéro de téléphone invalide.',
            'postal_code.regex' => 'Code postal à 5 chiffres.',
            'photos.max' => '5 photos au maximum.',
            'photos.*.mimes' => 'Photos au format JPEG ou PNG uniquement.',
            'photos.*.max' => 'Chaque photo doit faire moins de 15 Mo.',
            'consent.accepted' => 'Cochez la case pour accepter d\'être recontacté.',
        ]);

        if (empty($data['works']) && blank($data['message'] ?? null)) {
            throw ValidationException::withMessages(['works' => 'Dites-nous quels travaux vous envisagez.']);
        }

        $requests->receive($data, $request->file('photos', []), $request->ip());

        return redirect()->route('portal.request.thanks');
    }

    public function thanks(): View
    {
        return view('portal.request-thanks');
    }
}
