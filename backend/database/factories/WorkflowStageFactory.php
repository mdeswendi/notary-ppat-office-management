<?php

namespace Database\Factories;

use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStage>
 */
class WorkflowStageFactory extends Factory
{
    /**
     * **Test vocabulary, never plausible workflow vocabulary.** `UJI_TAHAP_1`
     * cannot be mistaken for a validated Notary or PPAT stage; `PEMERIKSAAN_BERKAS`
     * or `PENANDATANGANAN` could be, and D-104 is explicit that no stage sequence
     * may be seeded or inferred.
     *
     * Every behavioural flag is off and every optional field is null. A stage that
     * arrived pre-configured with `requires_approval` or a `target_days` would be
     * asserting something about how offices work that nobody has validated.
     *
     * `sequence_no` defaults to 1 because a factory cannot know the run it belongs
     * to — a caller building more than one uses {@see atPosition()}, or
     * `WorkflowTemplateFactory::withStages()`, which numbers them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 999999);

        return [
            'workflow_template_id' => WorkflowTemplate::factory(),
            'code' => 'UJI_TAHAP_'.$sequence,
            'name_id' => 'Tahap Uji '.$sequence,
            'name_en' => 'Test Stage '.$sequence,
            'sequence_no' => 1,
            'target_days' => null,
            'requires_approval' => false,
            'approval_permission' => null,
            'is_start_stage' => false,
            'is_completion_stage' => false,
        ];
    }

    /**
     * Place this stage at a position, with a code that matches it.
     *
     * Both are set together because `(workflow_template_id, code)` and
     * `(workflow_template_id, sequence_no)` are each unique: a run built by
     * varying only one of them collides on the other.
     */
    public function atPosition(int $position): static
    {
        return $this->state(fn (array $attributes): array => [
            'sequence_no' => $position,
            'code' => 'UJI_TAHAP_'.$position,
            'name_id' => 'Tahap Uji '.$position,
            'name_en' => 'Test Stage '.$position,
        ]);
    }

    public function code(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => $code,
        ]);
    }

    /**
     * A stage that asks for approval.
     *
     * The permission is a parameter with no default: which capability gates a
     * stage is content, and a factory choosing one would be inventing an approval
     * point (D-104). Whatever is passed must be a canonical code — the model
     * refuses anything else.
     */
    public function requiringApproval(?string $permission = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'requires_approval' => true,
            'approval_permission' => $permission,
        ]);
    }
}
