<?php

namespace App\Http\Requests;

class QuoteRequest extends DocumentRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->documentRules() + [
            'validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'work_start' => ['nullable', 'string', 'max:120'],
            'work_duration' => ['nullable', 'string', 'max:120'],
            'waste_estimate' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['validity_days' => 'durée de validité'];
    }

    /** @return array<string, mixed> */
    public function quoteAttributes(): array
    {
        return $this->headerAttributes(['validity_days', 'work_start', 'work_duration', 'waste_estimate']);
    }
}
