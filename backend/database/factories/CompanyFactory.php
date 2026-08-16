<?php

namespace Database\Factories;

use App\Domains\Party\Enums\CompanyEntityType;
use App\Models\Company;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'party_id' => Party::factory()->company(),
            'party_type' => 'COMPANY',
            'legal_name' => fake()->company(),
            'entity_type' => CompanyEntityType::PT,
            'tax_id' => null,
        ];
    }

    public function withTaxId(?string $taxId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'tax_id' => $taxId ?? fake()->numerify('################'),
        ]);
    }
}
