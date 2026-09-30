<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One amount-only line on an office quotation price record (D-137).
 *
 * The database keeps quantity and unit amount for compatibility with old rows,
 * but the quotation interface describes one component and its total amount.
 * Invoice lines continue using {@see BillingLineRequest}.
 */
class QuotationLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'line_number' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'line_amount' => ['prohibited'],
            'quantity' => ['prohibited'],
            'unit_amount' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lineAttributes(): array
    {
        $validated = $this->validated();

        return [
            'description' => $validated['description'],
            'quantity' => '1',
            'unit_amount' => $validated['amount'],
            ...array_intersect_key($validated, array_flip(['line_number'])),
        ];
    }
}
