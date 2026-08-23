<?php

namespace Database\Factories;

use App\Domains\Matter\Enums\MatterStageStatus;
use App\Models\MatterStageInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterStageInstance>
 */
class MatterStageInstanceFactory extends Factory
{
    /**
     * **Test vocabulary, never plausible workflow vocabulary**, matching
     * `WorkflowStageFactory`: no fixture may read like a validated Notary or PPAT
     * stage (D-104).
     *
     * The snapshot columns are populated here rather than copied from a template,
     * because a test asserting the snapshot works must be able to build an
     * instance whose names deliberately *differ* from the template's.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 999999);

        return [
            'stage_code' => 'UJI_TAHAP_'.$sequence,
            'stage_name_snapshot_id' => 'Tahap Uji '.$sequence,
            'stage_name_snapshot_en' => 'Test Stage '.$sequence,
            'sequence_no' => 1,
            'status' => MatterStageStatus::PENDING,
            'started_at' => null,
            'completed_at' => null,
            'assigned_user_id' => null,
            'approved_at' => null,
            'approved_by' => null,
        ];
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn (array $attributes): array => [
            'sequence_no' => $position,
            'stage_code' => 'UJI_TAHAP_'.$position,
            'stage_name_snapshot_id' => 'Tahap Uji '.$position,
            'stage_name_snapshot_en' => 'Test Stage '.$position,
        ]);
    }

    public function status(MatterStageStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}
