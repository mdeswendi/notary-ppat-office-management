<?php

namespace Database\Factories;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-2026-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'title' => $this->faker->sentence(3),
            'status' => InvoiceStatus::DRAFT,
            'currency' => 'IDR',
            'subtotal_amount' => '0.00',
            'total_amount' => '0.00',
        ];
    }

    public function inOffice(Office $office, ?User $creator = null): static
    {
        $creator ??= User::factory()->for($office)->create();

        return $this->state(fn (): array => [
            'office_id' => $office->getKey(),
            'created_by' => $creator->getKey(),
            'updated_by' => $creator->getKey(),
        ]);
    }

    public function issued(?User $issuer = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::ISSUED,
            'issued_at' => now(),
            'issued_by' => $issuer?->getKey() ?? $attributes['created_by'] ?? null,
        ]);
    }

    /**
     * Issued, and past its due date.
     *
     * There is no OVERDUE status: overdue is computed from `due_date` at read
     * time (D-124), so this state sets the date rather than a flag.
     */
    public function overdue(): static
    {
        return $this->issued()->state(fn (): array => [
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
    }
}
