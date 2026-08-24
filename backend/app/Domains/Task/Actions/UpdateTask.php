<?php

namespace App\Domains\Task\Actions;

use App\Domains\Task\Enums\TaskStatus;
use App\Domains\Task\Exceptions\TaskStatusNotEligible;
use App\Models\Task;
use App\Models\User;

/**
 * Correct a Task's ordinary attributes (M5.4, D-119).
 *
 * `title`, `description`, `priority` and `due_at` are fillable and ordinary.
 * `status` is neither, and is handled explicitly below.
 *
 * **Update may move a Task between the three live statuses and no further.**
 * `OPEN`, `IN_PROGRESS` and `WAITING` describe how work is going, which is exactly
 * what an ordinary edit is for. `COMPLETED` and `CANCELLED` are decisions with
 * their own capabilities — `tasks.complete` and `tasks.delete` — so letting
 * `tasks.update` write either would make one grant a silent superset of another
 * (D-091).
 *
 * **A settled Task cannot be edited at all.** Changing the title or the due date
 * of something already completed would rewrite what was done, and the record
 * exists to say what happened. Reopening first is the honest route, and it answers
 * to its own capability.
 *
 * **`assigned_to`, `office_id`, `created_by` and the parents are out of reach by
 * construction**, not by omission: none is fillable, the model refuses two of them
 * outright, and the Form Request refuses each by name so a caller who sends one is
 * told rather than quietly ignored.
 */
class UpdateTask
{
    /**
     * @param  array<string, mixed>  $attributes  ordinary fields, optionally including status
     */
    public function handle(User $actor, Task $task, array $attributes): Task
    {
        if (! $task->status->isOpen()) {
            throw new TaskStatusNotEligible($task->status, 'edited');
        }

        if (array_key_exists('status', $attributes)) {
            $requested = $attributes['status'] instanceof TaskStatus
                ? $attributes['status']
                : TaskStatus::from((string) $attributes['status']);

            if (! in_array($requested, TaskStatus::settableByUpdate(), true)) {
                throw new TaskStatusNotEligible($requested, 'set by an ordinary update');
            }

            $task->status = $requested;

            unset($attributes['status']);
        }

        $task->fill($attributes);
        $task->save();

        return $task;
    }
}
