<?php

namespace Database\Factories;

use App\Domains\Party\Enums\PartyType;
use App\Models\Office;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    /**
     * Creates a parent Office only when the caller has not supplied one, exactly
     * as OfficeFactory does for Organization.
     *
     * `party_type` and `office_id` are set here rather than through fill(),
     * because neither is fillable on the model — Office ownership is a security
     * boundary and party_type is immutable.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'party_type' => PartyType::INDIVIDUAL,
            'display_name' => fake()->name(),
            'primary_phone' => fake()->numerify('08##-####-####'),
            'primary_email' => fake()->unique()->safeEmail(),
        ];
    }

    public function individual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'party_type' => PartyType::INDIVIDUAL,
        ]);
    }

    public function company(): static
    {
        return $this->state(fn (array $attributes): array => [
            'party_type' => PartyType::COMPANY,
            'display_name' => fake()->company(),
        ]);
    }
}
