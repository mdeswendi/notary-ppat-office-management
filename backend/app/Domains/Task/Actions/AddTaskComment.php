<?php

namespace App\Domains\Task\Actions;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;

/**
 * Record a remark on a Task (M5.4, D-119).
 *
 * **`user_id` comes from the session, never the payload.** A fillable author would
 * let a caller sign somebody else's name, which is the one thing a comment must
 * never allow.
 *
 * **A comment can be added to a Task in any status**, including one completed or
 * cancelled. That is deliberate: explaining *why* something was closed is exactly
 * the remark most worth having, and it usually arrives just after the closing.
 * Freezing the conversation at completion would push that explanation somewhere
 * the record cannot see it.
 *
 * **Commenting answers to `tasks.view`.** The registry defines eight `tasks.*`
 * codes and none of them is `tasks.comment`; a person who may read the task may
 * say something about it. Requiring `tasks.update` would have meant only the
 * people who can edit the work can discuss it, which is not how an office runs —
 * and inventing a ninth code would change a canonical catalogue this milestone has
 * no authority to extend.
 */
class AddTaskComment
{
    public function handle(User $actor, Task $task, string $comment): TaskComment
    {
        $row = new TaskComment;

        $row->task_id = $task->getKey();
        $row->user_id = $actor->getKey();
        $row->comment = $comment;
        $row->save();

        return $row;
    }
}
