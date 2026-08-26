<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Actions\ManageBillingLines;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One line on a quotation or an invoice (M8.2, D-124).
 *
 * One request for both, because the shape is identical and the parent's `update`
 * ability is what authorizes either.
 *
 * **`line_amount` is `prohibited`.** It is `quantity * unit_amount` computed by
 * {@see ManageBillingLines}; a caller who could
 * submit it could produce a document whose lines do not add up to its total.
 *
 * **This is where an office puts tax, and the validator says nothing about it.**
 * A line reading "PPN 11%" priced by the office is a fact it asserted. The
 * software neither recognises nor computes it (D-124 section 9.4, O-040).
 */
class BillingLineRequest extends FormRequest
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

            // Non-negative, and zero is allowed: a line recording something
            // supplied at no charge is a legitimate thing to show a client.
            'quantity' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'unit_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],

            'line_number' => ['sometimes', 'integer', 'min:1', 'max:9999'],

            'line_amount' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lineAttributes(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['description', 'quantity', 'unit_amount', 'line_number']),
        );
    }
}
