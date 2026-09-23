<?php

namespace App\Http\Requests;

use App\Models\Worksite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorksiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['postal_code.regex' => 'Le code postal doit comporter 5 chiffres.'];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('postal_code')) {
            $this->merge(['postal_code' => preg_replace('/\s+/', '', (string) $this->input('postal_code'))]);
        }

        // Surface saisie à la française : « 85,5 ».
        if ($this->filled('roof_surface')) {
            $this->merge(['roof_surface' => str_replace([',', ' '], ['.', ''], (string) $this->input('roof_surface'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:160'],
            'postal_code' => ['required', 'regex:/^\d{5}$/'],
            'city' => ['required', 'string', 'max:80'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:30', 'regex:/^[\d\s.+()\-]+$/'],
            'access_notes' => ['nullable', 'string', 'max:2000'],
            'roof_type' => ['nullable', Rule::in(array_keys(Worksite::ROOF_TYPES))],
            'roof_surface' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'roof_pitch' => ['nullable', 'string', 'max:30'],
            'levels' => ['nullable', 'integer', 'min:0', 'max:50'],
            'accessibility' => ['nullable', Rule::in(array_keys(Worksite::ACCESSIBILITY))],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'label' => 'nom du chantier', 'address' => 'adresse', 'postal_code' => 'code postal', 'city' => 'ville',
            'contact_phone' => 'téléphone du contact', 'roof_surface' => 'surface', 'levels' => 'niveaux',
        ];
    }
}
