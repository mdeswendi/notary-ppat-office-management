<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Project\Actions\ChangeProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ChangeProjectStatusRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;

/**
 * A Project's business status (M3.3, D-091).
 *
 * Its own controller because it is its own capability. `projects.change_status`
 * writes `status` and nothing else, and `projects.update` reaches none of it.
 *
 * **No transition matrix.** This endpoint authorizes *who* may change status; it
 * does not decide *which* changes are legal, because no canonical document
 * defines that and inventing one would be indistinguishable, later, from a rule
 * somebody actually specified.
 *
 * Setting `ARCHIVED` here changes a **business status** and leaves the record
 * live and reachable. Archiving the record is `DELETE /projects/{project}`, which
 * sets `deleted_at`. The two are not wired to each other in either direction
 * (D-093).
 */
class ProjectStatusController extends Controller
{
    public function update(
        ChangeProjectStatusRequest $request,
        Project $project,
        ChangeProjectStatus $change,
    ): ProjectResource {
        $this->authorize('changeStatus', $project);

        $updated = $change->handle($request->user(), $project, $request->status());

        return new ProjectResource($updated->load(['office', 'picUser']));
    }
}
