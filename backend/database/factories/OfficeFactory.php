<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Office>
 */
class OfficeFactory extends Factory
{
    /**
     * Creates a parent Organization only when the caller has not supplied one.
     * `Office::factory()->for($organization)` overrides this, so tests can reuse
     * a hierarchy instead of accumulating stray organizations.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            // No uniqueness constraint exists on this column, so the factory
            // does not assert one either.
            'code' => strtoupper(fake()->unique()->bothify('OFC-###')),
            'name' => fake()->city().' Office',
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'province' => fake()->state(),
            'postal_code' => fake()->postcode(),
            'phone' => fake()->numerify('02#-####-####'),
            'email' => fake()->unique()->companyEmail(),
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ];
    }

    /**
     * A retired office.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
