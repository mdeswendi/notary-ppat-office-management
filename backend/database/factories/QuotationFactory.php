<?php

namespace Database\Factories;

use App\Domains\Billing\Enums\QuotationStatus;
use App\Models\Office;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_number' => 'QUO-2026-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'title' => $this->faker->sentence(3),
            'status' => QuotationStatus::DRAFT,
            'currency' => 'IDR',
            'subtotal_amount' => '0.00',
            'total_amount' => '0.00',
        ];
    }

    /**
     * Both the Office and the creator, kept in step.
     *
     * The composite foreign keys require the creator to work in the record's own
     * Office, so setting one without the other produces a row PostgreSQL refuses.
     */
    public function inOffice(Office $office, ?User $creator = null): static
    {
        $creator ??= User::factory()->for($office)->create();

        return $this->state(fn (): array => [
            'office_id' => $office->getKey(),
            'created_by' => $creator->getKey(),
            'updated_by' => $creator->getKey(),
        ]);
    }

    public function approved(?User $approver = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuotationStatus::APPROVED,
            'approved_at' => now(),
            'approved_by' => $approver?->getKey() ?? $attributes['created_by'] ?? null,
        ]);
    }
}
