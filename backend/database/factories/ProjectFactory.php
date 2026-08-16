<?php

namespace Database\Factories;

use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Project\Enums\ProjectStatus;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Creates a parent Office only when the caller has not supplied one, exactly
     * as PartyFactory does.
     *
     * `office_id`, `status`, `pic_user_id`, and `created_by` are set here rather
     * than through `fill()`, because none of them is fillable on the model —
     * Office ownership is a security boundary, status answers to
     * `projects.change_status`, and the other two are a scope predicate and actor
     * metadata respectively (D-088, D-091).
     *
     * `pic_user_id` and `created_by` default to **null**. M3.1 has no create
     * Action to set them, and a factory that invented a PIC would quietly make
     * every `ASSIGNED` test pass for the wrong reason. Tests that care state who
     * created or was assigned, explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => ProjectStatus::OPEN,
            'priority' => ProjectPriority::NORMAL,
            'pic_user_id' => null,
            'created_by' => null,
        ];
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'created_by' => $user->getKey(),
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'pic_user_id' => $user->getKey(),
        ]);
    }

    public function status(ProjectStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}
