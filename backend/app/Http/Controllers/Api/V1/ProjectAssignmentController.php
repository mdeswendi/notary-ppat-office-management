<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Project\Actions\AssignProjectPic;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\AssignProjectPicRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is in charge of a Project (M3.3, D-091).
 *
 * Its own controller because it is its own capability. `projects.assign` writes
 * `pic_user_id` and nothing else, and `projects.update` reaches none of it —
 * reassigning work is a different act from correcting a title, and the canonical
 * registry has said so since M1.
 *
 * **The candidate list is deliberately narrow.** It exists because an assignment
 * form needs names, and it answers to `projects.assign` **on this Project** —
 * no new permission was invented for it, and User Management permissions were
 * not broadened to populate a picker. It returns active users of the Project's
 * own Office and two fields each.
 *
 * That Office restriction is the same one the assignment itself enforces, and
 * for the same reason: `ASSIGNED` grants reach when `pic_user_id == actor.id`
 * (D-088), so a cross-office PIC would hand somebody reach their own scope never
 * included, without any role changing.
 */
class ProjectAssignmentController extends Controller
{
    /**
     * Set or clear the person in charge.
     */
    public function update(
        AssignProjectPicRequest $request,
        Project $project,
        AssignProjectPic $assign,
    ): ProjectResource {
        $this->authorize('assign', $project);

        $updated = $assign->handle($request->user(), $project, $request->picUserId());

        return new ProjectResource($updated->load(['office', 'picUser']));
    }

    /**
     * Who may be put in charge of this Project.
     *
     * Narrow form metadata, not a User API: it reads nothing else, writes
     * nothing, and returns exactly the fields a picker renders. Structural
     * eligibility only — **no role name is read** and no required role is
     * invented, because no canonical document names one.
     */
    public function options(Request $request, Project $project): JsonResponse
    {
        $this->authorize('assign', $project);

        $candidates = User::query()
            ->where('office_id', $project->office_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();

        return response()->json(['data' => ['users' => $candidates]]);
    }
}
