<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Project\Actions\RestoreProject;
use App\Domains\Project\ProjectVisibility;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArchivedProjectResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Archived Projects, and putting one back (M3.3, D-093).
 *
 * **A separate surface under a separate capability, and that is the point.**
 * Restoring needs a way to find the record, but the obvious shortcut — letting
 * ordinary Project view include soft-deleted rows behind a flag — would expose
 * archived work to everyone holding `projects.view`, which is a far larger group
 * than those holding `projects.restore`, and one nobody granted
 * archive-visibility to.
 *
 * So the split is strict in both directions:
 *
 *   projects.view      reaches live Projects. Never an archived one, anywhere.
 *   projects.restore   reaches archived Projects within its own Data Scope.
 *                      Never a live one, here.
 *
 * The Data Scope predicates are unchanged (D-088): `projects.restore` at
 * `OFFICE` reaches archived Projects of that Office, at `OWN` those the actor
 * created, and so on. Holding the capability does not widen reach.
 *
 * **Business status `ARCHIVED` is not what this surface lists.** It lists
 * soft-deleted records. A live Project whose status reads `ARCHIVED` appears in
 * the ordinary list, not here — the two states have unfortunately similar names,
 * and the archived resource shows the status precisely so a reader can see the
 * difference.
 *
 * Route ordering matters: `projects/archived` is registered before
 * `projects/{project}`, or the literal path would be swallowed as an id.
 */
class ArchivedProjectController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly ProjectVisibility $visibility,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewArchived', Project::class);

        $actor = $request->user();

        $query = $this->visibility->scope(
            Project::onlyTrashed()->with('office'),
            $actor,
            $this->resolver->resolve($actor, 'projects.restore'),
        );

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (BuilderContract $inner) use ($search): void {
                $inner->whereLike('title', "%{$search}%")
                    ->orWhereLike('project_number', "%{$search}%");
            });
        }

        $query->orderByDesc('deleted_at')->orderBy('id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        // Every row here is reachable through `projects.restore` by construction
        // — the scope predicate selected it — so the flag is true for all of
        // them without a second per-row check.
        return ArchivedProjectResource::collection(
            $query->paginate($perPage)->withQueryString()->through(
                fn (Project $project): ArchivedProjectResource => new ArchivedProjectResource(
                    $project,
                    ['can_restore' => true],
                )
            )
        );
    }

    /**
     * Put an archived Project back.
     *
     * Bound with `withTrashed`, because the record is invisible to the ordinary
     * binding — and the Policy ability is the one that looks at archived rows.
     */
    public function restore(Request $request, Project $project, RestoreProject $restore): ProjectResource
    {
        $this->authorize('restore', $project);

        $restored = $restore->handle($request->user(), $project);

        return new ProjectResource($restored->load(['office', 'picUser']));
    }
}
