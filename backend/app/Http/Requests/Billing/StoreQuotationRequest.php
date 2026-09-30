<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising a quotation (M8.2, D-124).
 *
 * **No `status`, no `quotation_number`, no totals.** The reference is allocated
 * (D-103); totals are the sum of the lines and belong to the line surface.
 * Legacy status columns are not part of the quotation API or business workflow.
 *
 * **No `tax` either.** D-124 section 9.4 forbids the column and the concept; an
 * office showing PPN adds a line it names itself.
 *
 * `prohibited` rather than merely absent, so a caller who sends one is told
 * plainly rather than having it silently dropped — the shape M7's requests use.
 */
class StoreQuotationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as &$item) {
            if (is_array($item) && array_key_exists('amount', $item)
                && ! array_key_exists('quantity', $item) && ! array_key_exists('unit_amount', $item)) {
                $item['quantity'] = '1';
                $item['unit_amount'] = $item['amount'];
                unset($item['amount']);
            }
        }

        $this->merge(['items' => $items]);
    }

    public function authorize(): bool
    {
        // The Policy decides, in the controller. A Form Request that authorized
        // would put the decision somewhere the D-048 scan does not look.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['IDR', 'USD', 'SGD', 'EUR'])],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Optional context, each re-resolved through its own domain's
            // visibility by the controller. The id here is never trusted.
            'client_party_id' => ['nullable', 'string', 'ulid'],
            'project_id' => ['nullable', 'string', 'ulid'],
            'matter_id' => ['nullable', 'string', 'ulid'],

            // The UI creates the price record and its lines as one operation so
            // failed line validation cannot leave an empty record.
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:description,quantity,unit_amount'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'items.*.unit_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'items.*.line_amount' => ['prohibited'],

            'status' => ['prohibited'],
            'quotation_number' => ['prohibited'],
            'subtotal_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'tax' => ['prohibited'],
        ];
    }

    /**
     * The ordinary fields, ready for the model.
     *
     * @return array<string, mixed>
     */
    public function quotationAttributes(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['title', 'description', 'currency', 'valid_until', 'notes']),
        );
    }

    /**
     * @return list<array{description: string, quantity: int|float|string, unit_amount: int|float|string}>
     */
    public function lineAttributes(): array
    {
        return $this->validated()['items'] ?? [];
    }
}
