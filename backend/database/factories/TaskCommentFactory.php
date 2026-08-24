<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskComment>
 */
class TaskCommentFactory extends Factory
{
    /**
     * The author defaults to a User in the parent Task's Office, so the fixture
     * matches what the product could actually produce — commenting requires
     * reaching the Task, and reaching it requires a scope that starts at the
     * Office.
     *
     * `task_comments` carries no `office_id` of its own (the ERD gives it none),
     * so nothing here writes one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),

            'user_id' => fn (array $attributes): ?string => $attributes['task_id'] === null
                ? null
                : User::factory()
                    ->for(Office::query()->findOrFail(
                        Task::query()->whereKey($attributes['task_id'])->value('office_id')
                    ))
                    ->create()
                    ->getKey(),

            'comment' => 'Catatan uji '.fake()->unique()->numberBetween(1, 999999),
        ];
    }

    public function forTask(Task $task): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_id' => $task->getKey(),
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->getKey(),
        ]);
    }
}
