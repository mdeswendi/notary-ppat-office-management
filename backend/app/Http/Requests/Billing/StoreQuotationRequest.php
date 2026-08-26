<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising a quotation (M8.2, D-124).
 *
 * **No `status`, no `quotation_number`, no totals.** The status is always `DRAFT`
 * and the reference is allocated (D-103); the totals are the sum of the lines and
 * belong to the line surface. Accepting any of them would let a caller decide
 * something the office decides.
 *
 * **No `tax` either.** D-124 section 9.4 forbids the column and the concept; an
 * office showing PPN adds a line it names itself.
 *
 * `prohibited` rather than merely absent, so a caller who sends one is told
 * plainly rather than having it silently dropped — the shape M7's requests use.
 */
class StoreQuotationRequest extends FormRequest
{
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
}
