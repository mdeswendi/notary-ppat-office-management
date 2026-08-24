<?php

namespace App\Domains\Task\Actions;

use App\Domains\Task\Exceptions\TaskStatusNotEligible;
use App\Models\Task;
use App\Models\User;

/**
 * Hand a Task to somebody, or take it back (M5.4, D-119).
 *
 * **`assigned_to` and `assigned_by` are written together.** The second records who
 * handed the work over, so it is meaningless without the first — and when the work
 * is unassigned, both are cleared rather than leaving a dangling assigner for a
 * task nobody holds.
 *
 * **Only live work may be reassigned.** A completed or cancelled Task is a record
 * of what happened, and moving its assignee afterwards would rewrite who did it.
 * Reopening first is the honest route, and it answers to its own capability.
 *
 * **Unassigning is allowed and is the same capability.** Somebody who may hand
 * work over may also take it back — splitting those would let a person assign a
 * task and then be unable to undo it.
 *
 * **`created_by` is never touched.** Reassignment moves the `ASSIGNED` predicate
 * and leaves `OWN` where it was, which is the whole reason the two are separate
 * columns.
 *
 * The Policy judged the actor, and the caller resolved the assignee through User
 * visibility in the Task's own Office. This action records who acted.
 */
class AssignTask
{
    /**
     * @param  User|null  $assignee  already resolved and authorized by the caller; null unassigns
     */
    public function handle(User $actor, Task $task, ?User $assignee): Task
    {
        if (! $task->status->isOpen()) {
            throw new TaskStatusNotEligible($task->status, 'reassigned');
        }

        $task->assigned_to = $assignee?->getKey();
        $task->assigned_by = $assignee === null ? null : $actor->getKey();
        $task->save();

        return $task;
    }
}
