<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising an invoice (M8.2, D-124).
 *
 * **`quotation_id` is how a quotation is "converted".** There is no
 * `quotations.convert` code; supplying an approved quotation here copies its
 * lines onto the new invoice under the canonical `invoices.create`. The
 * controller re-resolves the id through billing visibility and refuses one that
 * is not approved.
 *
 * No `status`, no `invoice_number`, no totals, and no `tax` — for the reasons
 * `StoreQuotationRequest` gives.
 */
class StoreInvoiceRequest extends FormRequest
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
            'title' => ['required_without:quotation_id', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['IDR', 'USD', 'SGD', 'EUR'])],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'client_party_id' => ['nullable', 'string', 'ulid'],
            'project_id' => ['nullable', 'string', 'ulid'],
            'matter_id' => ['nullable', 'string', 'ulid'],
            'quotation_id' => ['nullable', 'string', 'ulid'],

            'status' => ['prohibited'],
            'invoice_number' => ['prohibited'],
            'subtotal_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'tax' => ['prohibited'],
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
