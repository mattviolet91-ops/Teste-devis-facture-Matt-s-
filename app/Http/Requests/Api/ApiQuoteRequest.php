<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\QuoteRequest;

/**
 * Devis complet envoyé par l'API (Claude) : mêmes règles que l'éditeur de devis,
 * avec sections, lignes de texte, descriptions et options. Validité facultative
 * (celle des réglages par défaut).
 */
class ApiQuoteRequest extends QuoteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
        ]);
    }
}
