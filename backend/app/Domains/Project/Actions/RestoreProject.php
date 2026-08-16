<?php

namespace App\Domains\Project\Actions;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Restore a soft-deleted Project record (M3.3, D-093).
 *
 * Clears `deleted_at`, and that is the whole of it. It does **not**:
 *
 *   - change business status `ARCHIVED` back to `OPEN`
 *   - reverse a workflow, or undo a completion
 *   - re-allocate or alter the internal reference
 *   - restore an Office or a PIC that were never lost
 *
 * Everything the record carried it still carries. The reference in particular is
 * the same one it always had — it was never released, so there is nothing to
 * reissue (D-096).
 *
 * `projects.restore` is the only capability that operates on an archived row, and
 * `ProjectPolicy::restore` is the only ability that looks at one. Ordinary view
 * stays blind to soft-deleted records.
 */
class RestoreProject
{
    public function handle(User $actor, Project $project): Project
    {
        return DB::transaction(function () use ($actor, $project): Project {
            $project->restore();

            $project->updated_by = $actor->getKey();
            $project->save();

            return $project->refresh();
        });
    }
}
