<?php

namespace App\Domains\Project\Actions;

use App\Domains\Project\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Change a Project's business status (M3.3, D-091).
 *
 * **Status, and nothing else.** Not the PIC, not the Office, not the reference,
 * and not the persistence state.
 *
 * **There is no transition matrix, deliberately.** This action does not decide
 * whether `COMPLETED → OPEN` is sensible, whether a cancelled Project may reopen,
 * or whether anything requires approval first. Which status may follow which is
 * an operational rule no canonical document defines, and encoding one from memory
 * is the failure CLAUDE.md section 62 prohibits one domain removed. M3 authorizes
 * *who* may change status through `projects.change_status`; the office decides
 * *what* the change means.
 *
 * **`ARCHIVED` here is a business status, not a deletion** (D-093). Setting it
 * leaves the record live and reachable; archiving the *record* is
 * {@see ArchiveProject}, which sets `deleted_at`. The two have unfortunately
 * similar names and are deliberately not wired to each other in either direction.
 *
 * **`completed_at` is not touched.** Coupling it to `COMPLETED` would be a
 * business rule nobody has stated — and it would be wrong the first time somebody
 * corrects a status set by mistake. The column stays under ordinary update until
 * a canonical decision says otherwise.
 */
class ChangeProjectStatus
{
    public function handle(User $actor, Project $project, ProjectStatus $status): Project
    {
        return DB::transaction(function () use ($actor, $project, $status): Project {
            $project->status = $status;
            $project->updated_by = $actor->getKey();
            $project->save();

            return $project->refresh();
        });
    }
}
