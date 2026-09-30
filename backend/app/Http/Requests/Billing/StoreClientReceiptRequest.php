<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_party_id' => ['required', 'string', 'ulid'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'method_code' => ['required', 'string', Rule::in(PaymentMethod::values())],
            'reference' => ['prohibited'],
            'invoice_id' => ['prohibited'],
        ];
    }

    /** @return array<string, mixed> */
    public function receiptAttributes(): array
    {
        return [
            ...array_intersect_key($this->validated(), array_flip(['amount', 'received_on', 'method_code'])),
            'currency' => 'IDR',
        ];
    }
}
