<?php

namespace Database\Factories;

use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Matter;
use App\Models\PpatMatter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpatMatter>
 */
class PpatMatterFactory extends Factory
{
    /**
     * **The Matter is created first and the Office follows it**, as in
     * `PpatDeedFactory`: the composite key `(matter_id, office_id)` accepts no
     * other pairing, so generating the two independently would produce a fixture the
     * database refuses.
     *
     * A `PPAT` Matter, because an extension row on a Notary Matter would be a
     * fixture no surface can create.
     *
     * **The two boolean flags default to `true`, matching the column defaults**, and
     * nothing in the product branches on either. `land_office_region` is free text and defaults to null
     * because there is no catalogue to draw a value from — the D-116 ruling for
     * `document_type_code`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory()->state(['domain' => MatterDomain::PPAT]),

            'office_id' => fn (array $attributes): string => Matter::query()
                ->findOrFail($attributes['matter_id'])
                ->office_id,

            'land_office_region' => null,
            'tax_processing_required' => true,
            'registration_required' => true,
            'notes' => null,
        ];
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn (array $attributes): array => [
            'matter_id' => $matter->getKey(),
            'office_id' => $matter->office_id,
        ]);
    }
}
