<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowTemplate>
 */
class WorkflowTemplateFactory extends Factory
{
    /**
     * Creates a parent Office only when the caller has not supplied one, exactly
     * as `ServiceTypeFactory` and `ProjectFactory` do.
     *
     * **The values are deliberately not realistic, and that is the point.** No
     * validated Notary or PPAT workflow exists (D-104), so a fixture reading
     * `PEMERIKSAAN_BERKAS` or `Proses Standar AJB` could later be mistaken for
     * an approved process — by a reader, by a copy-paste into a seeder, or by
     * somebody reconstructing "how the office works" from the test suite. `UJI_`
     * codes and `Alur Uji` names cannot be mistaken for anything.
     *
     * `service_type_id` is left null: an unbound template is the generic case,
     * and a factory that invented a Service Type would quietly build the
     * cross-office binding the composite foreign key exists to forbid. A test
     * wanting one attaches it explicitly, which is the point.
     *
     * `office_id` and `code` are set here rather than through `fill()`, because
     * neither is fillable: both are identity, and the model refuses to change
     * them after creation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 999999);

        return [
            'office_id' => Office::factory(),
            'service_type_id' => null,
            'code' => 'UJI_ALUR_'.$sequence,
            'name_id' => 'Alur Uji '.$sequence,
            'name_en' => 'Test Workflow '.$sequence,
            'version' => 1,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function code(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => $code,
        ]);
    }

    /**
     * A later iteration of the same template row.
     *
     * `version` is a counter, not a second row (D-111), so this is a state on one
     * record rather than a way to create a sibling.
     */
    public function version(int $version): static
    {
        return $this->state(fn (array $attributes): array => [
            'version' => $version,
        ]);
    }

    /**
     * Marked default — a designation under no cardinality rule, so nothing stops
     * a test from marking two.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }

    /**
     * Retired from new instantiation, and still readable — the only lifecycle a
     * template has in M4.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Attach a run of stages, numbered from 1.
     *
     * **Structure only.** The stages carry test codes and no approval, no target,
     * and no start or completion marker: which stages have those is workflow
     * content, and a factory that supplied a plausible-looking run would be
     * seeding exactly what D-104 forbids.
     */
    public function withStages(int $count = 3): static
    {
        return $this->afterCreating(function (WorkflowTemplate $template) use ($count): void {
            foreach (range(1, $count) as $position) {
                WorkflowStageFactory::new()
                    ->for($template, 'template')
                    ->atPosition($position)
                    ->create();
            }
        });
    }
}
