<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\Party;
use App\Models\Property;
use App\Models\PropertyOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyOwner>
 */
class PropertyOwnerFactory extends Factory
{
    /**
     * **The Property is created first and everything follows it.** A link Office is
     * its Property, and its Party must live in that Office too — the composite keys
     * accept nothing else, so generating them independently would produce a fixture
     * the database refuses.
     *
     * **`is_current` defaults to true and `effective_until` to null**, which is the
     * only combination the CHECK and the model guard both accept for a live link. The
     * `closed()` state writes the other consistent pair.
     *
     * **`ownership_percentage` defaults to null**, not 100: a sole owner needs no
     * percentage, and defaulting to 100 would make every fixture assert a share the
     * office never recorded.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),

            'office_id' => fn (array $attributes): string => Property::query()
                ->findOrFail($attributes['property_id'])
                ->office_id,

            // A Party in the Property own Office, which the composite key requires.
            'party_id' => fn (array $attributes): string => Party::factory()
                ->create(['office_id' => $attributes['office_id']])
                ->getKey(),

            'ownership_percentage' => null,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'is_current' => true,
            'source_matter_id' => null,
        ];
    }

    /**
     * File the link against a particular Property, moving the Office with it.
     */
    public function forProperty(Property $property): static
    {
        return $this->state(fn (array $attributes): array => [
            'property_id' => $property->getKey(),
            'office_id' => $property->office_id,
            'party_id' => fn (): string => Party::factory()
                ->create(['office_id' => $property->office_id])
                ->getKey(),
        ]);
    }

    public function party(Party $party): static
    {
        return $this->state(fn (array $attributes): array => [
            'party_id' => $party->getKey(),
        ]);
    }

    /**
     * A share of a jointly-held parcel.
     *
     * **No sum is enforced anywhere**, so several of these may total whatever the
     * office recorded — including more or less than 100 (D-121).
     */
    public function share(string $percentage): static
    {
        return $this->state(fn (array $attributes): array => [
            'ownership_percentage' => $percentage,
        ]);
    }

    /**
     * A closed link — the shape history takes once ownership moves on.
     *
     * Writes the pair together, as the product does: a row that has ended is not
     * current, and the CHECK refuses the contradiction.
     */
    public function closed(string $until = '2026-06-30'): static
    {
        return $this->state(fn (array $attributes): array => [
            'effective_until' => $until,
            'is_current' => false,
        ]);
    }
}
