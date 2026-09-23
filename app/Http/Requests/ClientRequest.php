<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $individual = $this->input('type') === 'particulier';

        return [
            'type' => ['required', Rule::in(array_keys(Client::TYPES))],
            'status' => ['sometimes', Rule::in(array_keys(Client::STATUSES))],
            'civility' => ['nullable', Rule::in(Client::CIVILITIES)],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => [$individual ? 'required' : 'nullable', 'string', 'max:80'],
            'company_name' => [$individual ? 'nullable' : 'required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[\d\s.+()\-]+$/'],
            'phone_2' => ['nullable', 'string', 'max:30', 'regex:/^[\d\s.+()\-]+$/'],
            'address' => ['nullable', 'string', 'max:160'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', Rule::in(array_keys(Client::SOURCES))],
            'source_detail' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'create_worksite' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'last_name.required' => 'Le nom est obligatoire pour un particulier.',
            'company_name.required' => 'Le nom de la société est obligatoire.',
            'phone.regex' => 'Le numéro de téléphone ne doit contenir que des chiffres.',
            'phone_2.regex' => 'Le numéro de téléphone ne doit contenir que des chiffres.',
        ];
    }

    public function attributes(): array
    {
        return [
            'first_name' => 'prénom', 'last_name' => 'nom', 'company_name' => 'société',
            'phone' => 'téléphone', 'phone_2' => 'second téléphone', 'address' => 'adresse',
            'postal_code' => 'code postal', 'city' => 'ville', 'notes' => 'notes',
        ];
    }
}
