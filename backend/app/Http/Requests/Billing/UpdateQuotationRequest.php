<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting a draft quotation (M8.2, D-124).
 *
 * **The parents are not editable here.** Moving a quotation to a different
 * client, Project or Matter is not a correction — it is a different offer, and
 * the record of who was quoted what should not change under an existing number.
 * They are `prohibited` rather than absent so a caller is told.
 *
 * `DRAFT` only, enforced by the Policy before this runs.
 */
class UpdateQuotationRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['IDR', 'USD', 'SGD', 'EUR'])],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'status' => ['prohibited'],
            'quotation_number' => ['prohibited'],
            'subtotal_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'tax' => ['prohibited'],
            'client_party_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'matter_id' => ['prohibited'],
        ];
    }

    /**
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
