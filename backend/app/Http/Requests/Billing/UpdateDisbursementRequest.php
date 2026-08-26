<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting a recorded cost (M8.2, D-124).
 *
 * **The amount is editable here**, unlike a payment's. The difference is who else
 * has seen it: a payment is a claim about money that came from outside the office
 * and the catalogue deliberately gives it no correction path (O-050), where a
 * disbursement is the office's own note about its own spending.
 *
 * The parents stay `prohibited`: re-pointing a cost at a different Matter is a
 * different cost.
 */
class UpdateDisbursementRequest extends FormRequest
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
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:9999999999999'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['IDR', 'USD', 'SGD', 'EUR'])],
            'incurred_on' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'status' => ['prohibited'],
            'tax' => ['prohibited'],
            'client_party_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'matter_id' => ['prohibited'],
            'invoice_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function disbursementAttributes(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['description', 'amount', 'currency', 'incurred_on', 'reference', 'notes']),
        );
    }
}
