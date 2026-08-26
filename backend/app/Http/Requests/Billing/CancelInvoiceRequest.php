<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Withdrawing a bill (M8.2, D-124).
 *
 * **The reason is optional but recorded on the row**, not only in the audit
 * trail. Cancelling an invoice a client has already seen is the one act on this
 * surface somebody will ask about months later, and an explanation that lives
 * only in `audit_logs` is invisible on the page where the question is asked.
 */
class CancelInvoiceRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function cancellationReason(): ?string
    {
        $reason = $this->validated()['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
