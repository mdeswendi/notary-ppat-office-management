<?php

namespace App\Domains\Task\Actions;

use App\Domains\Task\Exceptions\TaskStatusNotEligible;
use App\Models\Task;
use App\Models\User;

/**
 * Remove a Task that is finished or called off (M5.4, D-119).
 *
 * `COMPLETED` or `CANCELLED` only. **Nothing still live can be deleted** — finish
 * it or cancel it first, so no work in flight disappears without anybody saying
 * what became of it. The mirror of `DocumentStatus::isDeletable()` (D-117): a
 * capability that must be restricted is restricted by state rather than by
 * inventing a permission.
 *
 * **Soft delete, and the comments stay.** `task_comments.task_id` cascades on a
 * *hard* delete, which nothing here performs; a soft delete leaves every remark
 * intact, so restoring a task later restores the conversation with it. Erasing
 * what people said because the work was withdrawn would lose the record of why.
 *
 * There is **no restore endpoint.** `tasks.delete` is the only canonical code near
 * this act, and reading it as *"may also undelete"* would make one capability do
 * two jobs (D-091) — the same position `DeleteDocument` takes.
 */
class DeleteTask
{
    public function handle(User $actor, Task $task): Task
    {
        if (! $task->status->isDeletable()) {
            throw new TaskStatusNotEligible($task->status, 'deleted');
        }

        $task->delete();

        return $task;
    }
}
