<?php

namespace Database\Factories;

use App\Domains\Ppat\Enums\PpatWarkahStatus;
use App\Models\PpatDeed;
use App\Models\PpatWarkah;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpatWarkah>
 */
class PpatWarkahFactory extends Factory
{
    /**
     * **The deed is created first and the Office follows it**, which the composite key
     * requires.
     *
     * **`completeness_percentage` defaults to 0 and no state computes it.** An empty
     * bundle has collected nothing, and a factory that seeded a percentage would put a
     * number into fixtures that does not correspond to any item — the exact
     * disagreement `recalculateCompleteness()` exists to prevent. Tests that care about
     * the number create items and call the model.
     *
     * **`FINALIZED` and `ARCHIVED` have no state helper**, because no code path
     * produces them (O-041). A fixture in an unreachable status would let a test assert
     * behaviour the product does not have.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ppat_deed_id' => PpatDeed::factory(),

            'office_id' => fn (array $attributes): string => PpatDeed::query()
                ->findOrFail($attributes['ppat_deed_id'])
                ->office_id,

            'status' => PpatWarkahStatus::INCOMPLETE,
            'completeness_percentage' => 0,

            'verified_at' => null,
            'verified_by' => null,
            'finalized_at' => null,
            'finalized_by' => null,

            'archive_location' => null,
            'notes' => null,
        ];
    }

    public function forDeed(PpatDeed $deed): static
    {
        return $this->state(fn (array $attributes): array => [
            'ppat_deed_id' => $deed->getKey(),
            'office_id' => $deed->office_id,
        ]);
    }

    /**
     * One of the three reachable statuses.
     *
     * Deliberately typed as the enum so a caller cannot pass `FINALIZED` as a string
     * without noticing it is unreachable.
     */
    public function status(PpatWarkahStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    /**
     * Where the physical bundle sits. Free text — it describes a shelf.
     */
    public function shelved(string $location): static
    {
        return $this->state(fn (array $attributes): array => [
            'archive_location' => $location,
        ]);
    }
}
