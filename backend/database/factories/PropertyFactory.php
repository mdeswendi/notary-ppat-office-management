<?php

namespace Database\Factories;

use App\Domains\Ppat\Enums\PropertyType;
use App\Models\Office;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * **`property_number` defaults to null**, which is a complete Property rather
     * than a draft: no creation path allocates one until M7.3, and a factory that
     * invented a number would make uniqueness tests pass for the wrong reason.
     *
     * **`status` defaults to null and no state sets it.** The ERD gives the column no
     * vocabulary, and inventing one in a factory would put a value into fixtures that
     * no code path can produce — which is how a test comes to assert behaviour the
     * product does not have.
     *
     * **`right_type` is a plain string, not an enum**, because the ERD calls its codes
     * examples. `HAK_MILIK` is used here because it is the commonest Indonesian land
     * right and makes fixtures readable — not because the column is constrained to it.
     *
     * Values are deliberately non-legal: `Uji` marks a fixture, and no real
     * certificate number appears anywhere in this file.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),

            'property_number' => null,

            'property_type' => PropertyType::LAND,
            'right_type' => 'HAK_MILIK',

            'certificate_number' => 'UJI-'.fake()->unique()->numberBetween(1, 999999),
            'certificate_date' => null,

            'land_area' => 250.00,
            'building_area' => null,

            'measurement_letter_number' => null,
            'measurement_letter_date' => null,

            'address' => 'Jalan Uji No. '.fake()->numberBetween(1, 200),
            'village' => null,
            'district' => null,
            'city' => null,
            'province' => null,
            'postal_code' => null,
            'latitude' => null,
            'longitude' => null,

            'status' => null,

            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function inOffice(Office|string $office): static
    {
        return $this->state(fn (array $attributes): array => [
            'office_id' => $office instanceof Office ? $office->getKey() : $office,
        ]);
    }

    public function type(PropertyType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'property_type' => $type,
        ]);
    }

    /**
     * A right type the office typed. **No validation, by design** — the column takes
     * whatever the office records (D-121).
     */
    public function rightType(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'right_type' => $code,
        ]);
    }

    public function numbered(string $number): static
    {
        return $this->state(fn (array $attributes): array => [
            'property_number' => $number,
        ]);
    }
}
