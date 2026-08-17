<?php

namespace Database\Factories;

use App\Domains\MasterData\Enums\ServiceTypeDomain;
use App\Models\Office;
use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceType>
 */
class ServiceTypeFactory extends Factory
{
    /**
     * Creates a parent Office only when the caller has not supplied one, exactly
     * as `ProjectFactory` and `PartyFactory` do.
     *
     * **The values are deliberately not realistic, and that is the point.** No
     * validated Notary or PPAT service catalogue exists (D-102), so a fixture
     * reading `AJB` or `Pendirian PT` could later be mistaken for an approved
     * entry — by a reader, by a copy-paste into a seeder, or by somebody
     * reconstructing "what services the office offers" from the test suite.
     * `UJI_` codes and `Layanan Uji` names cannot be mistaken for anything.
     *
     * `office_id`, `code`, and `domain` are set here rather than through `fill()`,
     * because none of them is fillable: all three are identity, and the model
     * refuses to change them after creation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 999999);

        return [
            'office_id' => Office::factory(),
            'code' => 'UJI_'.$sequence,
            'domain' => ServiceTypeDomain::NOTARY,
            'name_id' => 'Layanan Uji '.$sequence,
            'name_en' => 'Test Service '.$sequence,
            'description_id' => null,
            'description_en' => null,
            'is_active' => true,
            'sort_order' => 0,
            'default_duration_days' => null,
        ];
    }

    public function domain(ServiceTypeDomain $domain): static
    {
        return $this->state(fn (array $attributes): array => [
            'domain' => $domain,
        ]);
    }

    public function code(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => $code,
        ]);
    }

    /**
     * Retired from new selection, and still readable — the only lifecycle a
     * Service Type has in M4.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
