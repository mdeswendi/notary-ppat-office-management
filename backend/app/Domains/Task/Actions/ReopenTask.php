<?php

namespace App\Domains\Task\Actions;

use App\Domains\Task\Enums\TaskStatus;
use App\Domains\Task\Exceptions\TaskStatusNotEligible;
use App\Models\Task;
use App\Models\User;

/**
 * Put a completed Task back to work (M5.4, D-119).
 *
 * `COMPLETED` → `IN_PROGRESS`, and the completion pair is cleared together.
 *
 * **`IN_PROGRESS` rather than back to `OPEN`.** The work was started and finished
 * once; sending it to `OPEN` would describe a task nobody has touched, which is
 * not what happened. `IN_PROGRESS` is the honest state for something being
 * revisited, and the assignee it still carries is who is revisiting it.
 *
 * **A cancelled Task cannot be reopened**, and that asymmetry is deliberate.
 * Completing states the work is done, which can be wrong and is worth correcting;
 * cancelling states it will not happen, and un-saying that quietly would erase the
 * decision. Somebody who cancelled by mistake raises a new task, which leaves both
 * facts on the record.
 *
 * **Its own capability.** `tasks.reopen` is canonical and separate from
 * `tasks.complete` — an office may well want the people who close work to be a
 * different set from those who can undo it, and the registry already says so.
 * Folding reopen into complete would have made one grant do two jobs (D-091).
 *
 * `$actor` is taken and deliberately unused for persistence: there is no
 * `reopened_by` column, because the ERD gives Task none and inventing one would
 * extend the canonical field list for an event the audit store is meant to hold.
 * It stays in the signature because that store will need it, and because an action
 * that cannot name who acted is one somebody later has to re-plumb.
 */
class ReopenTask
{
    public function handle(User $actor, Task $task): Task
    {
        if (! $task->status->isReopenable()) {
            throw new TaskStatusNotEligible($task->status, 'reopened');
        }

        $task->status = TaskStatus::IN_PROGRESS;
        $task->completed_at = null;
        $task->completed_by = null;
        $task->save();

        return $task;
    }
}
