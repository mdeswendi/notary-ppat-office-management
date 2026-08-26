<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting a draft invoice (M8.2, D-124).
 *
 * `DRAFT` only — after issue the row is finalized and the only remaining act is
 * `cancel`. The parents and the quotation link are `prohibited`: re-pointing a
 * bill at a different client is a different bill.
 */
class UpdateInvoiceRequest extends FormRequest
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
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'status' => ['prohibited'],
            'invoice_number' => ['prohibited'],
            'subtotal_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'tax' => ['prohibited'],
            'client_party_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'matter_id' => ['prohibited'],
            'quotation_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceAttributes(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['title', 'description', 'currency', 'due_date', 'notes']),
        );
    }
}
