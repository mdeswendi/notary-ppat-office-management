<?php

namespace App\Domains\Task\Actions;

use App\Domains\Task\Enums\TaskStatus;
use App\Domains\Task\Exceptions\TaskStatusNotEligible;
use App\Models\Task;
use App\Models\User;

/**
 * Call off work that will not happen (M5.4, D-119).
 *
 * Anything still live → `CANCELLED`. A task already completed or cancelled is 422.
 *
 * **Cancelling is not deleting, and that distinction is the point.** `CANCELLED`
 * says the office decided not to do this; a soft delete says the row should stop
 * appearing. `CLAUDE.md` section 30 prefers a state over removal, and cancelling
 * is what makes deletion available afterwards without losing the decision.
 *
 * **It answers to `tasks.delete`**, which is the closest canonical capability and
 * the reason no seventh code was invented: the registry defines eight `tasks.*`
 * codes and none of them is `tasks.cancel`. Reading `delete` as *"may take this
 * out of circulation"* covers both cancelling and removing, and the status rule is
 * what keeps them in the right order — nothing live can be deleted, so cancelling
 * is the step that precedes it.
 *
 * **The completion pair is left alone**, because a cancelled task was never
 * completed. Clearing fields that are already null would suggest they might not
 * have been.
 */
class CancelTask
{
    public function handle(User $actor, Task $task): Task
    {
        if (! $task->status->isCancellable()) {
            throw new TaskStatusNotEligible($task->status, 'cancelled');
        }

        $task->status = TaskStatus::CANCELLED;
        $task->save();

        return $task;
    }
}
