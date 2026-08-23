<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Matter\Actions\AssignMatterPic;
use App\Http\Controllers\Api\V1\Concerns\ResolvesMatterDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matter\AssignMatterPicRequest;
use App\Http\Resources\MatterResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is in charge of a Matter (M4.4, D-109).
 *
 * Its own controller because it is its own capability. `*.matters.assign` writes
 * `pic_user_id` and nothing else, and `*.matters.update` reaches none of it —
 * reassigning work is a different act from correcting a title, and the canonical
 * registry has said so since M1.
 *
 * **The candidate list is deliberately narrow.** It exists because an assignment
 * form needs names, and it answers to `*.matters.assign` **on this Matter** — no
 * new permission was invented for it, and User Management permissions were not
 * broadened to populate a picker. It returns active users of the Matter's own
 * Office and two fields each.
 *
 * That Office restriction is the same one the assignment itself enforces, and for
 * the same reason: `ASSIGNED` grants reach when `pic_user_id == actor.id`
 * (D-100), so a cross-office PIC would hand somebody reach their own scope never
 * included, without any role changing.
 *
 * Matter PIC and Project PIC are unrelated. Assigning one never writes the other,
 * and neither widens the other's `ASSIGNED` reach.
 */
class MatterAssignmentController extends Controller
{
    use ResolvesMatterDomain;

    /**
     * Set or clear the person in charge.
     */
    public function update(
        AssignMatterPicRequest $request,
        string $matter,
        AssignMatterPic $assign,
    ): MatterResource {
        $domain = $this->matterDomain($request);
        $record = $this->resolveMatter($domain, $matter);

        $this->authorize('assign', [$record, $domain]);

        $updated = $assign->handle($request->user(), $record, $request->picUserId());

        return new MatterResource($updated->load(['office', 'project', 'serviceType', 'picUser']));
    }

    /**
     * Who may be put in charge of this Matter.
     *
     * Narrow form metadata, not a User API: it reads nothing else, writes
     * nothing, and returns exactly the fields a picker renders. Structural
     * eligibility only — **no role name is read** and no required role is
     * invented, because no canonical document names one.
     */
    public function options(Request $request, string $matter): JsonResponse
    {
        $domain = $this->matterDomain($request);
        $record = $this->resolveMatter($domain, $matter);

        $this->authorize('assign', [$record, $domain]);

        $candidates = User::query()
            ->where('office_id', $record->office_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();

        return response()->json(['data' => ['users' => $candidates]]);
    }
}
