<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\VatRate;
use App\Models\Worksite;
use App\Support\Money;
use App\Support\Percent;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'worksite_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:200'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'work_start' => ['nullable', 'string', 'max:120'],
            'work_duration' => ['nullable', 'string', 'max:120'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'discount_value' => ['nullable', 'string', 'max:20'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.type' => ['required', Rule::in(['item', 'section', 'text'])],
            'lines.*.title' => ['nullable', 'string', 'max:255'],
            'lines.*.description' => ['nullable', 'string', 'max:5000'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price' => ['nullable', 'string', 'max:20'],
            'lines.*.vat_rate' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'lines.*.discount_percent' => ['nullable', 'string', 'max:10'],
            'lines.*.catalog_item_id' => ['nullable', 'integer', 'exists:catalog_items,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'client_id' => 'client', 'validity_days' => 'durée de validité', 'title' => 'objet du devis',
            'lines.*.title' => 'désignation', 'lines.*.quantity' => 'quantité', 'lines.*.unit_price' => 'prix unitaire',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $worksiteId = $this->input('worksite_id');
            if ($worksiteId && ! Worksite::query()->whereKey($worksiteId)->where('client_id', $this->input('client_id'))->exists()) {
                $validator->errors()->add('worksite_id', 'Ce chantier n\'appartient pas au client choisi.');
            }

            if ($this->filled('discount_type') && $this->parsedDiscount() === null) {
                $validator->errors()->add('discount_value', 'Remise invalide.');
            }

            foreach ($this->input('lines', []) as $index => $line) {
                $number = $index + 1;
                if (in_array($line['type'] ?? '', ['item', 'section'], true) && trim((string) ($line['title'] ?? '')) === '') {
                    $validator->errors()->add("lines.$index.title", "Ligne $number : la désignation est obligatoire.");
                }
                if (($line['type'] ?? '') !== 'item') {
                    continue;
                }
                if (! Quantity::parse($line['quantity'] ?? null)) {
                    $validator->errors()->add("lines.$index.quantity", "Ligne $number : quantité invalide (ex. 1 ou 12,5).");
                }
                if (self::price($line) === null) {
                    $validator->errors()->add("lines.$index.unit_price", "Ligne $number : prix invalide (ex. 8 ou 8,50).");
                }
                $discount = self::discount($line);
                if ($discount === null || $discount > 10000) {
                    $validator->errors()->add("lines.$index.discount_percent", "Ligne $number : remise invalide (0 à 100 %).");
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    public function quoteAttributes(): array
    {
        $data = $this->safe()->only([
            'client_id', 'worksite_id', 'title', 'validity_days', 'work_start', 'work_duration',
            'payment_terms', 'notes', 'internal_notes',
        ]);

        $data['worksite_id'] = ($data['worksite_id'] ?? null) ?: null;
        $data['discount_type'] = $this->filled('discount_type') && $this->parsedDiscount() ? $this->input('discount_type') : null;
        $data['discount_value'] = $data['discount_type'] ? $this->parsedDiscount() : 0;

        return $data;
    }

    /**
     * Lignes prêtes à enregistrer (montants en centimes, quantités en millièmes).
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        $defaultRate = (int) (VatRate::query()->where('is_default', true)->value('rate') ?? 0);

        return collect($this->input('lines', []))->map(function (array $line) use ($defaultRate) {
            $type = $line['type'];
            $base = [
                'type' => $type,
                'title' => trim((string) ($line['title'] ?? '')) ?: null,
                'description' => trim((string) ($line['description'] ?? '')) ?: null,
                'hide_prices' => $type === 'section' && ! empty($line['hide_prices']),
            ];

            if ($type !== 'item') {
                return $base + ['quantity' => 0, 'unit_price' => 0, 'vat_rate' => 0];
            }

            return $base + [
                'quantity' => Quantity::parse($line['quantity']),
                'unit' => trim((string) ($line['unit'] ?? '')) ?: null,
                'unit_price' => self::price($line) ?? 0,
                'vat_rate' => isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (int) $line['vat_rate'] : $defaultRate,
                'discount_percent' => self::discount($line) ?? 0,
                'is_optional' => ! empty($line['is_optional']),
                'is_offered' => ! empty($line['is_offered']),
                'catalog_item_id' => ($line['catalog_item_id'] ?? null) ?: null,
            ];
        })->values()->all();
    }

    /** Champ vide = 0 €. */
    private static function price(array $line): ?int
    {
        $value = trim((string) ($line['unit_price'] ?? ''));

        return $value === '' ? 0 : Money::parse($value);
    }

    /** Champ vide = pas de remise. */
    private static function discount(array $line): ?int
    {
        $value = trim((string) ($line['discount_percent'] ?? ''));

        return $value === '' ? 0 : Percent::parse($value);
    }

    private function parsedDiscount(): ?int
    {
        $value = (string) $this->input('discount_value');

        return $this->input('discount_type') === 'percent' ? Percent::parse($value) : Money::parse($value);
    }

    public function client(): Client
    {
        return Client::query()->findOrFail($this->input('client_id'));
    }
}
