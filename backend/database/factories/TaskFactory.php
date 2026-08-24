<?php

namespace Database\Factories;

use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Task\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * `office_id`, `status`, `created_by`, the assignment pair and the completion
     * pair are set here rather than through `fill()`, because none is fillable:
     * Office is identity, status answers to its own capabilities, and the rest is
     * attribution the application decides.
     *
     * **`created_by` creates a User in the same Office rather than defaulting to
     * null.** The column is `NOT NULL` — a task always has somebody who raised it
     * — so a null default would make the factory unusable rather than neutral.
     * Same Office by construction, because the composite key accepts no other.
     *
     * **`assigned_to` defaults to null**, which is a complete Task rather than a
     * draft: work often exists before anybody holds it, and a factory that invented
     * an assignee would quietly make every `ASSIGNED` test pass for the wrong
     * reason. The `assignedTo()` state says who, explicitly.
     *
     * **`due_at` defaults to null.** A default date would make roughly half the
     * fixtures overdue depending on when the suite ran, which is how a time-
     * dependent test becomes flaky.
     *
     * Values are deliberately non-legal: `Pekerjaan Uji` is obviously a fixture.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'project_id' => null,
            'matter_id' => null,

            'title' => 'Tugas Uji '.fake()->unique()->numberBetween(1, 999999),
            'description' => null,
            'status' => TaskStatus::OPEN,
            'priority' => ProjectPriority::NORMAL,

            'assigned_to' => null,
            'assigned_by' => null,

            // A User in the **same Office**, so the OWN and OFFICE predicates agree
            // by construction. A creator from another Office would be a fixture no
            // creation path could produce, and would make an OFFICE test pass
            // through the OWN branch by accident.
            'created_by' => fn (array $attributes): ?string => $attributes['office_id'] === null
                ? null
                : User::factory()->for(Office::query()->findOrFail($attributes['office_id']))->create()->getKey(),

            'due_at' => null,
            'completed_at' => null,
            'completed_by' => null,
        ];
    }

    /**
     * Raise the Task in a particular Office.
     *
     * Also moves the default creator into that Office, so `created_by` does not
     * silently stay behind in the Office the definition generated.
     */
    public function inOffice(Office|string $office): static
    {
        $officeId = $office instanceof Office ? $office->getKey() : $office;

        return $this->state(fn (array $attributes): array => [
            'office_id' => $officeId,
            'created_by' => fn (): string => User::factory()
                ->for(Office::query()->findOrFail($officeId))
                ->create()
                ->getKey(),
        ]);
    }

    /**
     * Name the person who raised it — the `OWN` predicate.
     *
     * **This moves the Task into that user's Office, and it has to.** Document's
     * equivalent state deliberately does not, because `documents.created_by` is a
     * plain foreign key and a creator may sit in another Office. A Task's is
     * **composite** — `(created_by, office_id) → users (id, office_id)` — so a
     * creator from elsewhere is unrepresentable, and a state that did not move the
     * Office would produce a fixture the database refuses.
     *
     * The consequence is worth naming: for Task, `OWN` is always a subset of
     * `OFFICE`. That is a real effect of making assignment structural, not a
     * weakening of the predicate — `OWN` and `ASSIGNED` still select different
     * rows within one Office, which is the distinction that matters.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'office_id' => $user->office_id,
            'created_by' => $user->getKey(),
        ]);
    }

    /**
     * Hand it to somebody — the `ASSIGNED` predicate.
     *
     * Writes the pair together, as the product does: `assigned_by` is meaningless
     * without an assignee.
     */
    public function assignedTo(User $user, ?User $by = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'assigned_to' => $user->getKey(),
            'assigned_by' => ($by ?? $user)->getKey(),
        ]);
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    public function priority(ProjectPriority $priority): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }

    public function due(string $when): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_at' => $when,
        ]);
    }

    /**
     * Finished, with the completion pair written together.
     *
     * A PostgreSQL CHECK and a model guard both refuse half of one, so the state
     * that sets a status must set both columns.
     */
    public function completed(?User $by = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaskStatus::COMPLETED,
            'completed_at' => now(),
            'completed_by' => fn (array $resolved): string => $by?->getKey()
                ?? (string) $resolved['created_by'],
        ]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'office_id' => $project->office_id,
            'project_id' => $project->getKey(),
            'created_by' => fn (): string => User::factory()
                ->for(Office::query()->findOrFail($project->office_id))
                ->create()
                ->getKey(),
        ]);
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn (array $attributes): array => [
            'office_id' => $matter->office_id,
            'matter_id' => $matter->getKey(),
            'created_by' => fn (): string => User::factory()
                ->for(Office::query()->findOrFail($matter->office_id))
                ->create()
                ->getKey(),
        ]);
    }
}
