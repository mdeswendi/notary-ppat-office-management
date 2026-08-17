<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Project\Actions\ArchiveProject;
use App\Domains\Project\Actions\CreateProject;
use App\Domains\Project\Actions\UpdateProject;
use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Project\Enums\ProjectStatus;
use App\Domains\Project\ProjectVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Project records (M3.3).
 *
 * Thin (CLAUDE.md section 35): authorize, take validated input, call an Action,
 * return a Resource. Scope rules live in {@see ProjectVisibility} and mutation
 * rules in the Actions, where both can be read and tested without HTTP.
 *
 * **Deliberately absent, each for its own reason:** assignment and status
 * changes, which have their own capabilities and controllers (D-091);
 * archived-record discovery, which answers to `projects.restore` rather than
 * `projects.view` (D-093); Party participation, which has its own controller and
 * its own two capabilities (M3.4, D-098); and anything Matter-shaped, which is
 * M4. `DELETE` archives — Project records are never destroyed.
 *
 * **`projects.view_all` is not consulted anywhere in this class.** It is
 * superseded by Data Scope `ALL` for reach (D-090), and a second reach mechanism
 * is exactly what must not exist.
 */
class ProjectController extends Controller
{
    /**
     * The mutation abilities the interface asks about, and the capability each
     * one answers to.
     */
    private const CAPABILITIES = [
        'can_update' => 'projects.update',
        'can_assign' => 'projects.assign',
        'can_change_status' => 'projects.change_status',
        'can_archive' => 'projects.archive',
    ];

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly ProjectVisibility $visibility,
    ) {}

    /**
     * Projects the caller may see.
     *
     * Visibility is applied **in the query**, so a scoped caller's SQL never
     * selects a row they may not open — the pagination total counts only what
     * they may see, and no filter can widen it. Soft-deleted Projects are absent
     * through the model's global scope; finding those is the archived surface's
     * job, under a different permission.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);

        $actor = $request->user();

        $query = $this->visibility->scope(
            Project::query()->with(['office', 'picUser']),
            $actor,
            $this->resolver->resolve($actor, 'projects.view'),
        );

        if ($search = trim((string) $request->query('search', ''))) {
            // Grouped so the search cannot escape the visibility constraint.
            //
            // `project_number` is searchable and safe: it is ordinary office
            // identification rather than sensitive identity, and the scope
            // predicate still bounds every row. Because references are unique
            // only within an Office (D-096), one reference may legitimately match
            // rows in several Offices for an ALL-scoped caller — the search does
            // not pretend otherwise.
            $query->where(function (BuilderContract $inner) use ($search): void {
                $inner->whereLike('title', "%{$search}%")
                    ->orWhereLike('project_number', "%{$search}%");
            });
        }

        // Unrecognized filter values are ignored rather than erroring: a stale
        // bookmark should show the unfiltered list, not a 422.
        if (ProjectStatus::tryFrom((string) $request->query('status', '')) !== null) {
            $query->where('status', $request->query('status'));
        }

        if (ProjectPriority::tryFrom((string) $request->query('priority', '')) !== null) {
            $query->where('priority', $request->query('priority'));
        }

        $query->orderByDesc('created_at')->orderBy('id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->paginate($perPage)->withQueryString();

        $capabilities = $this->capabilityMap(collect($page->items()), $actor);

        return ProjectResource::collection($page->through(
            fn (Project $project): ProjectResource => new ProjectResource(
                $project,
                $capabilities[$project->getKey()] ?? []
            )
        ));
    }

    /**
     * Create a Project in the actor's own Office.
     *
     * The Policy is asked without an Office argument, which is the honest shape:
     * there is no destination to choose. `ALL` grants cross-office reach over
     * existing Projects, never cross-office creation (D-097), and the Request
     * refuses an `office_id` outright so an over-post fails loudly.
     */
    public function store(StoreProjectRequest $request, CreateProject $create): JsonResponse
    {
        $this->authorize('create', Project::class);

        $project = $create->handle($request->user(), $request->projectAttributes());

        return $this->one($project->load(['office', 'picUser']), $request->user())
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Project $project): ProjectResource
    {
        $this->authorize('view', $project);

        return $this->one($project->load(['office', 'picUser']), $request->user());
    }

    /**
     * Ordinary attributes only. The Request prohibits everything else, the model
     * refuses it from mass assignment, and the model guards Office and reference
     * independently of both (D-091).
     */
    public function update(
        UpdateProjectRequest $request,
        Project $project,
        UpdateProject $update,
    ): ProjectResource {
        $this->authorize('update', $project);

        $updated = $update->handle($request->user(), $project, $request->projectAttributes());

        return $this->one($updated->load(['office', 'picUser']), $request->user());
    }

    /**
     * Archive the record. Not a deletion, and not a status change — see
     * {@see ArchiveProject}.
     */
    public function archive(Request $request, Project $project, ArchiveProject $archive): Response
    {
        $this->authorize('archive', $project);

        $archive->handle($request->user(), $project);

        return response()->noContent();
    }

    private function one(Project $project, User $actor): ProjectResource
    {
        $capabilities = $this->capabilityMap(collect([$project]), $actor);

        return new ProjectResource($project, $capabilities[$project->getKey()] ?? []);
    }

    /**
     * Which mutation each Project on this page permits, computed in bulk.
     *
     * **Four resolver calls and four queries for the whole page**, not per row.
     * Asking the Policy per row would mean four uncached resolver resolutions
     * plus four `exists()` queries for every Project listed — the N+1 M2.6 found
     * in the Party reverse view. The actor's effective access does not vary by
     * row, so it is resolved once per capability and the record predicate is
     * asked for every id at once.
     *
     * The answers are identical to `ProjectPolicy`'s, because both reduce to the
     * same {@see ProjectVisibility} predicate over the same resolved access.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, array<string, bool>>
     */
    private function capabilityMap(Collection $projects, User $actor): array
    {
        $ids = $projects->map(fn (Project $project): string => $project->getKey())->all();

        if ($ids === []) {
            return [];
        }

        $reachable = [];

        foreach (self::CAPABILITIES as $flag => $permission) {
            $reachable[$flag] = $this->visibility->reachableProjectKeys(
                $ids,
                $actor,
                $this->resolver->resolve($actor, $permission),
            );
        }

        $map = [];

        foreach ($ids as $id) {
            foreach (self::CAPABILITIES as $flag => $permission) {
                $map[$id][$flag] = isset($reachable[$flag][$id]);
            }
        }

        return $map;
    }
}
