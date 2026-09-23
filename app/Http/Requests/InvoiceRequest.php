<?php

namespace App\Http\Requests;

class InvoiceRequest extends DocumentRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->documentRules() + [
            'due_days' => ['required', 'integer', 'min:0', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['due_days' => 'délai de paiement'];
    }

    /** @return array<string, mixed> */
    public function invoiceAttributes(): array
    {
        return $this->headerAttributes(['due_days']);
    }
}
