<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording that money arrived (M8.2, D-124, O-050).
 *
 * **Everything here is final.** The catalogue gives payments no update, delete or
 * reject, so the values submitted now are the values this record keeps — which is
 * why the amount, the currency, the method and the date are all required rather
 * than defaulted.
 *
 * **`status` is `prohibited`.** A payment is always born `PENDING`; recording and
 * verifying are separate capabilities on purpose, and letting a caller submit
 * `VERIFIED` would collapse the one control this surface has.
 *
 * **`paid_at` may be in the past and may not be in the future.** A transfer
 * noticed on Monday may have landed on Friday; one that has not happened yet is
 * not a payment.
 */
class StorePaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['IDR', 'USD', 'SGD', 'EUR'])],
            'method_code' => ['required', Rule::in(PaymentMethod::values())],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'status' => ['prohibited'],
            'verified_at' => ['prohibited'],
            'verified_by' => ['prohibited'],
            'invoice_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentAttributes(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['amount', 'currency', 'method_code', 'paid_at', 'reference', 'notes']),
        );
    }
}
