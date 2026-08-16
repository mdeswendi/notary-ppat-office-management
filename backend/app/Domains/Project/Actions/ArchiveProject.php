<?php

namespace App\Domains\Project\Actions;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Archive a Project by soft-deleting the record (M3.3, D-093).
 *
 * Sets `deleted_at` and touches nothing else. In particular it does **not** set
 * business status to `ARCHIVED`: those are two different states with
 * unfortunately similar names, and coupling them would make one of them
 * unobservable. A Project can be archived-the-record while its business status
 * still reads `IN_PROGRESS` — which is exactly the honest description of work
 * that was filed away mid-flight.
 *
 * The internal reference is **not released**. It belongs to the record that
 * received it, the counter does not rewind, and the next allocation for that
 * Office-year continues from where it was (D-096). There is no reuse feature and
 * there will not be one.
 *
 * Not a deletion. `projects.restore` reverses this, and only this.
 */
class ArchiveProject
{
    public function handle(User $actor, Project $project): void
    {
        DB::transaction(function () use ($actor, $project): void {
            $project->updated_by = $actor->getKey();
            $project->save();

            $project->delete();
        });
    }
}
