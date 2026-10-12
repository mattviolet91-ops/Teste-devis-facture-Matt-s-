<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Confirmation par le client d'une intervention urgente (nature de l'urgence, nom, signature). */
class UrgentConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'signature' => ['required', 'string', 'max:800000'],
            'agree' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Décrivez l\'urgence (ex. fuite dans la chambre, tuiles envolées).',
            'name.required' => 'Indiquez le nom et prénom du client.',
            'signature.required' => 'Le client doit signer dans le cadre avec le doigt.',
            'agree.accepted' => 'Cochez la case de confirmation.',
        ];
    }
}
