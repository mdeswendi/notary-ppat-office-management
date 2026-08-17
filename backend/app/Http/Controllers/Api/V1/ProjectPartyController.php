<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Project\Actions\AddProjectParty;
use App\Domains\Project\Actions\RemoveProjectParty;
use App\Domains\Project\Actions\UpdateProjectParty;
use App\Domains\Project\ProjectParticipantVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectPartyRequest;
use App\Http\Requests\Project\UpdateProjectPartyRequest;
use App\Http\Resources\ProjectPartyResource;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectParty;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Which Parties take part in a Project (M3.4, D-098).
 *
 * **Two capabilities, two questions.** Reading the list answers to
 * `projects.parties.view`; adding, correcting, and removing answer to
 * `projects.parties.manage`. Neither implies the other and `projects.update`
 * reaches neither — the registry defines separate codes and this controller
 * honours them separately.
 *
 * **Managing participation is not authority to discover Parties.** Every path
 * that names a Party — the candidate list and the link body alike — resolves it
 * through {@see ProjectParticipantVisibility}, which applies the Party-domain
 * permission for that Party's own subtype. A submitted `party_id` is re-resolved
 * through that same authorized query rather than trusted, so an id obtained
 * elsewhere cannot become a participation.
 *
 * The nested binding is resolved against the parent Project explicitly, so a
 * participation belonging to a different Project cannot be updated or removed
 * through this one's address.
 */
class ProjectPartyController extends Controller
{
    public function __construct(
        private readonly ProjectParticipantVisibility $participants,
    ) {}

    /**
     * The Parties taking part in this Project.
     *
     * Current state, not history: `project_parties` has no period columns and
     * this returns exactly the rows that exist (D-098).
     *
     * Party visibility is computed in **bulk** — two queries at most, one per
     * subtype branch — rather than per row. The actor's effective access does
     * not vary by row, so a per-row check would be the N+1 M2.6 measured on the
     * Party reverse view.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('viewAny', [ProjectParty::class, $project]);

        $participations = ProjectParty::query()
            ->where('project_id', $project->getKey())
            // Archived Parties are loaded deliberately: a participation the
            // office recorded stays listed after the Party is retired.
            ->with(['party' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $parties = $participations->pluck('party')->filter()->all();
        $viewable = $this->participants->viewableKeys($parties, $request->user());

        // A Policy ability, never a permission code — it runs the same
        // ProjectPartyPolicy -> EffectiveAccessResolver -> Data Scope chain the
        // mutation endpoints authorize through, so the flag cannot disagree with
        // what those endpoints would accept. Presentation only; each of them
        // authorizes again (CLAUDE.md sections 24 and 28).
        //
        // Evaluated once for the whole list rather than per row: participation
        // authority is a property of the parent Project, which does not vary
        // between rows.
        $canManage = $request->user()->can('manage', [ProjectParty::class, $project]);

        return response()->json([
            'data' => $participations->map(fn (ProjectParty $participation): array => (
                new ProjectPartyResource(
                    $participation,
                    ['can_manage' => $canManage],
                    isset($viewable[$participation->party_id]),
                )
            )->toArray($request))->all(),

            'meta' => [
                'total' => $participations->count(),
                'can_manage' => $canManage,
            ],
        ]);
    }

    /**
     * Candidate Parties for a new participation.
     *
     * **Narrow on purpose, and doubly authorized.** `projects.parties.manage`
     * over this Project is necessary but not sufficient: the candidate query
     * additionally applies `parties.view` to Individuals and `companies.view` to
     * Companies, each at its own Data Scope, so this endpoint can never surface
     * a Party the actor could not already reach in the Party directory. No User
     * or Party permission was widened to populate a picker.
     *
     * Candidates are same-Office as the Project and not archived. The first
     * because a cross-office participation is unrepresentable anyway and
     * offering one would be an existence oracle for another Office's directory;
     * the second because a retired record should not be picked for new work.
     */
    public function options(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage', [ProjectParty::class, $project]);

        $query = $this->participants->candidateQuery($request->user(), $project);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->whereLike('display_name', "%{$search}%");
        }

        $candidates = $query->orderBy('display_name')->limit(50)->get()
            ->map(fn (Party $party): array => [
                'id' => $party->getKey(),
                'display_name' => $party->display_name,
                'party_type' => $party->party_type->value,
            ])->all();

        return response()->json(['data' => ['parties' => $candidates]]);
    }

    /**
     * Link a Party to this Project.
     *
     * The submitted `party_id` is re-resolved through the authorized candidate
     * query. A Party that is in another Office, archived, of a subtype the actor
     * cannot see, or simply nonexistent produces one indistinguishable 422 —
     * telling them apart would answer a question the caller has no permission to
     * ask.
     */
    public function store(
        StoreProjectPartyRequest $request,
        Project $project,
        AddProjectParty $add,
    ): JsonResponse {
        $this->authorize('manage', [ProjectParty::class, $project]);

        $party = $this->participants->resolveCandidate(
            $request->user(),
            $project,
            $request->validated('party_id'),
        );

        if ($party === null) {
            abort(422, 'Select a Party from this Project\'s office.');
        }

        $participation = $add->handle(
            $request->user(),
            $project,
            $party,
            $request->participationAttributes(),
        );

        return $this->resource($request, $project, $participation)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Correct a participation's role, primary designation, or notes.
     *
     * Never its endpoints — the request refuses `party_id`, `project_id`, and
     * `office_id`, and the model withholds all three from mass assignment.
     */
    public function update(
        UpdateProjectPartyRequest $request,
        Project $project,
        string $projectParty,
        UpdateProjectParty $updateParticipation,
    ): JsonResource {
        $this->authorize('manage', [ProjectParty::class, $project]);

        $participation = $updateParticipation->handle(
            $this->resolveParticipation($project, $projectParty),
            $request->participationAttributes(),
        );

        return $this->resource($request, $project, $participation);
    }

    /**
     * Unlink a Party. Deletes the relationship row and nothing else.
     */
    public function destroy(
        Request $request,
        Project $project,
        string $projectParty,
        RemoveProjectParty $remove,
    ): Response {
        $this->authorize('manage', [ProjectParty::class, $project]);

        $remove->handle($this->resolveParticipation($project, $projectParty));

        return response()->noContent();
    }

    /**
     * Resolve a participation that genuinely belongs to this Project, or 404.
     *
     * The Project constraint is in the query rather than checked afterwards, and
     * a foreign participation answers 404 rather than 403: a 403 would confirm
     * the row exists and belongs to a Project the caller cannot name, which is
     * exactly what nested binding must not leak.
     */
    private function resolveParticipation(Project $project, string $participationId): ProjectParty
    {
        $participation = ProjectParty::query()
            ->where('project_id', $project->getKey())
            ->whereKey($participationId)
            ->first();

        if ($participation === null) {
            throw (new ModelNotFoundException)->setModel(ProjectParty::class, [$participationId]);
        }

        return $participation;
    }

    private function resource(
        Request $request,
        Project $project,
        ProjectParty $participation,
    ): ProjectPartyResource {
        $participation->load(['party' => fn ($query) => $query->withTrashed()]);

        $viewable = $participation->party === null
            ? []
            : $this->participants->viewableKeys([$participation->party], $request->user());

        return new ProjectPartyResource(
            $participation,
            ['can_manage' => true],
            isset($viewable[$participation->party_id]),
        );
    }
}
