<?php

namespace App\Domains\Task\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Task\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Raise a Task (M5.4, D-119).
 *
 * **Six fields are decided here and cannot be requested**, following `CreateMatter`
 * and `UploadDocument`:
 *
 *   office_id     the actor's own Office, never a choice
 *   status        OPEN
 *   created_by    the actor — the OWN predicate, and immutable thereafter
 *   assigned_to   null unless the caller assigned it in the same request
 *   assigned_by   the actor, and only when assigned_to is set
 *   completed_*   null; completion answers to its own capability
 *
 * **Office is the actor's own, not inherited from the parent.** This differs from
 * `CreateMatter`, which takes its Project's Office because a Matter is always a
 * child. A Task may have no parent at all, so the actor's Office is the only thing
 * that can decide it — and when a parent *is* named, the Policy has already
 * required it to be in that same Office, so the two agree by argument rather than
 * by coincidence. The composite keys refuse any row where they would not.
 *
 * **Assigning at creation is optional and is still the assign capability.** The
 * caller may hand the work over in the same request, but only if they hold
 * `tasks.assign` — checked by the Policy before this runs. A Task with no assignee
 * is complete rather than a draft: work often exists before anybody has picked it
 * up.
 *
 * **Nothing is notified and nothing is logged.** `audit_logs` does not exist and
 * D-115 forbids the half-measure; a `notifications` table is ERD section 34's and
 * belongs to the milestone that owns it. `created_by`, `assigned_by` and the
 * timestamps record who and when on the row itself.
 *
 * The Policy judged the actor before this ran, and the caller resolved any parent
 * and assignee through their own visibility. This action does not re-decide
 * authorization; it records who acted.
 */
class CreateTask
{
    public function __construct(private readonly EventRecorder $events) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     * @param  Project|null  $project  already resolved and authorized by the caller
     * @param  Matter|null  $matter  already resolved and authorized by the caller
     * @param  User|null  $assignee  already resolved and authorized by the caller
     */
    public function handle(
        User $actor,
        array $attributes,
        ?Project $project = null,
        ?Matter $matter = null,
        ?User $assignee = null,
    ): Task {
        return DB::transaction(function () use ($actor, $attributes, $project, $matter, $assignee): Task {
            $task = new Task;

            // None of these is fillable, by design. Assigning them explicitly is
            // the point: a reader sees every system-controlled field in one place
            // rather than inferring it from what the Request omitted.
            $task->office_id = $actor->office_id;
            $task->project_id = $project?->getKey();
            $task->matter_id = $matter?->getKey();
            $task->status = TaskStatus::OPEN;
            $task->created_by = $actor->getKey();

            // Written together or not at all: `assigned_by` records who handed the
            // work over, so it is meaningless without somebody to have handed it
            // to.
            $task->assigned_to = $assignee?->getKey();
            $task->assigned_by = $assignee === null ? null : $actor->getKey();

            $task->completed_at = null;
            $task->completed_by = null;

            $task->fill($attributes);
            $task->save();

            $this->events->created($task, $actor, ActivityType::TASK_CREATED, [
                'title' => $task->title,
            ]);

            return $task;
        });
    }
}
