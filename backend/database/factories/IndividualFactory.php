<?php

namespace Database\Factories;

use App\Models\Individual;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Individual>
 */
class IndividualFactory extends Factory
{
    /**
     * Builds its own INDIVIDUAL Party when none is supplied. Passing a COMPANY
     * Party is rejected by the composite foreign key, which is the point.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'party_id' => Party::factory()->individual(),
            'party_type' => 'INDIVIDUAL',
            'full_name' => fake()->name(),
            // Sensitive fields are left null by default so a test that cares
            // about them has to say so.
            'nik' => null,
            'npwp' => null,
        ];
    }

    public function withIdentity(?string $nik = null, ?string $npwp = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'nik' => $nik ?? fake()->numerify('################'),
            'npwp' => $npwp ?? fake()->numerify('################'),
        ]);
    }
}
