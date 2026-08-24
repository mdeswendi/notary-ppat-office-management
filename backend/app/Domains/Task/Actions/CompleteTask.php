<?php

namespace App\Domains\Task\Actions;

use App\Domains\Task\Enums\TaskStatus;
use App\Domains\Task\Exceptions\TaskStatusNotEligible;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Mark a Task done (M5.4, D-119).
 *
 * `OPEN`, `IN_PROGRESS` or `WAITING` → `COMPLETED`. Anything else is 422:
 * completing twice is not an act the office can perform, and completing something
 * cancelled would contradict the decision to call it off.
 *
 * **The completion pair is written together** — `completed_at` and `completed_by`
 * — which a PostgreSQL CHECK and a model guard both enforce. Half of a completion
 * is a row nobody can explain.
 *
 * **The person completing need not be the assignee.** A supervisor closing work
 * somebody else finished is ordinary office behaviour, and `completed_by` records
 * who actually pressed it rather than assuming it was whoever held the task.
 *
 * **No reason is stored, and the plan asked for one.** There is no column for it:
 * `03_DATABASE_ERD.md` section 15 gives Task no completion note, and adding one
 * would extend the canonical field list for something the conversation already
 * holds — a task's remarks live in `task_comments`, which is where an explanation
 * belongs and where it stays readable next to everything else that was said.
 */
class CompleteTask
{
    public function handle(User $actor, Task $task): Task
    {
        if (! $task->status->isCompletable()) {
            throw new TaskStatusNotEligible($task->status, 'completed');
        }

        $task->status = TaskStatus::COMPLETED;
        $task->completed_at = Date::now();
        $task->completed_by = $actor->getKey();
        $task->save();

        return $task;
    }
}
