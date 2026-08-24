<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Task\TaskVisibility;
use App\Models\Task;
use App\Models\User;

/**
 * Who may work with Tasks (M5.4, D-119).
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048), so M1's
 * rules apply unchanged: canonical permissions only, a role grant with no Data
 * Scope grants nothing, an active DENY override wins, expired overrides are
 * ignored, and Spatie's direct user-permission grants never participate. No role
 * name is read anywhere, and `SUPER_ADMIN` receives no bypass.
 *
 * ## Seven capabilities, and none implies another
 *
 * The registry defines eight `tasks.*` codes and this class honours seven of them
 * separately — the discipline D-091 applies to Project and D-110 to participation.
 * `tasks.update` does not reach `complete`; `complete` does not reach `reopen`;
 * `assign` reaches none of them.
 *
 * **`tasks.reopen` is its own code and is treated as one.** The M5.4 plan folded
 * reopening into completion; the registry does not, and an office may reasonably
 * let more people close work than un-close it.
 *
 * **`tasks.view_all` is not consulted anywhere in this class.** It is superseded
 * by Data Scope `ALL` for reach (D-090), exactly as `projects.view_all` and
 * `*.matters.view_all` are, and a second reach mechanism is what must not exist.
 *
 * ## What is decided here and what is decided in the Actions
 *
 * This class answers *who*. Whether the Task's current status permits the act is
 * the Action's, and it answers 422 rather than 403 — the caller is authorized and
 * would succeed on a task in a different state.
 *
 * The one exception is `delete`, where the status rule is *also* reflected in the
 * capability flag the interface reads, so a button that cannot work is never
 * offered. The endpoint still re-checks.
 */
class TaskPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly TaskVisibility $visibility,
    ) {}

    /**
     * May the actor open the Task list?
     *
     * A grant carrying only `TEAM` reaches nothing, so it is refused outright
     * rather than serving a reliably empty page.
     */
    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, 'tasks.view')
        );
    }

    public function view(User $actor, Task $task): bool
    {
        return $this->reaches($actor, 'tasks.view', $task);
    }

    /**
     * May the actor raise a Task in their own Office?
     *
     * Always their own: `ALL` is reach over records that already exist, never
     * authority to decide which Office a new one belongs to.
     */
    public function create(User $actor, ?string $officeId = null): bool
    {
        return $this->visibility->permitsCreationIn(
            $actor,
            $this->resolver->resolve($actor, 'tasks.create'),
            $officeId,
        );
    }

    public function update(User $actor, Task $task): bool
    {
        return $this->reaches($actor, 'tasks.update', $task);
    }

    /**
     * May the actor hand this Task to somebody, or take it back?
     */
    public function assign(User $actor, Task $task): bool
    {
        return $this->reaches($actor, 'tasks.assign', $task);
    }

    public function complete(User $actor, Task $task): bool
    {
        return $this->reaches($actor, 'tasks.complete', $task);
    }

    public function reopen(User $actor, Task $task): bool
    {
        return $this->reaches($actor, 'tasks.reopen', $task);
    }

    /**
     * Cancel or remove a Task.
     *
     * **One capability for both**, because cancelling is what makes deletion
     * available: nothing live may be removed, so calling work off is the step that
     * precedes it. The registry defines no `tasks.cancel`, and inventing one would
     * extend a canonical catalogue this milestone has no authority to change.
     */
    public function delete(User $actor, Task $task): bool
    {
        return $this->reaches($actor, 'tasks.delete', $task);
    }

    /**
     * May the actor add a remark?
     *
     * **`tasks.view`, not `tasks.update`.** A person who may read the task may say
     * something about it; requiring the edit capability would mean only those who
     * can change the work may discuss it. There is no `tasks.comment` code, and
     * this milestone does not add one.
     */
    public function comment(User $actor, Task $task): bool
    {
        return $this->view($actor, $task);
    }

    private function reaches(User $actor, string $permission, Task $task): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $task,
        );
    }
}
