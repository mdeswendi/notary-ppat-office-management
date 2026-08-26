<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording a cost the office carried (M8.2, D-124).
 *
 * **No `status`, because the table has no such column** — `disbursements.*` has
 * no lifecycle verb, so there is nothing for a status to mean.
 *
 * **No tax field and no tax concept.** A disbursement records that money was
 * spent for a client. Whether it was a tax is not something this software knows,
 * computes or gates on, which is what keeps O-040 intact.
 */
class StoreDisbursementRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['IDR', 'USD', 'SGD', 'EUR'])],
            'incurred_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'client_party_id' => ['nullable', 'string', 'ulid'],
            'project_id' => ['nullable', 'string', 'ulid'],
            'matter_id' => ['nullable', 'string', 'ulid'],
            'invoice_id' => ['nullable', 'string', 'ulid'],

            'status' => ['prohibited'],
            'tax' => ['prohibited'],
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
