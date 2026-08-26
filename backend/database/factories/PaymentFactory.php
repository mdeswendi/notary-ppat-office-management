<?php

namespace Database\Factories;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'amount' => '1000000.00',
            'currency' => 'IDR',
            'status' => PaymentStatus::PENDING,
            'method_code' => PaymentMethod::BANK_TRANSFER,
            'paid_at' => now()->toDateString(),
        ];
    }

    /**
     * The invoice carries the Office, so both are set from it.
     */
    public function forInvoice(Invoice $invoice, ?User $creator = null): static
    {
        $creator ??= User::factory()->for($invoice->office)->create();

        return $this->state(fn (): array => [
            'office_id' => $invoice->office_id,
            'invoice_id' => $invoice->getKey(),
            'created_by' => $creator->getKey(),
        ]);
    }

    public function verified(?User $verifier = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::VERIFIED,
            'verified_at' => now(),
            'verified_by' => $verifier?->getKey() ?? $attributes['created_by'] ?? null,
        ]);
    }
}
