<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'received_on' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'method_code' => ['sometimes', 'required', 'string', Rule::in(PaymentMethod::values())],
            'client_party_id' => ['prohibited'],
            'reference' => ['prohibited'],
            'invoice_id' => ['prohibited'],
        ];
    }

    /** @return array<string, mixed> */
    public function receiptAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip(['amount', 'received_on', 'method_code']));
    }
}
