<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Matter\Actions\AddMatterParty;
use App\Domains\Matter\Actions\RemoveMatterParty;
use App\Domains\Matter\Actions\UpdateMatterParty;
use App\Domains\Party\ParticipantVisibility;
use App\Http\Controllers\Api\V1\Concerns\ResolvesMatterDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matter\StoreMatterPartyRequest;
use App\Http\Requests\Matter\UpdateMatterPartyRequest;
use App\Http\Resources\MatterPartyResource;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Which Parties take part in a Matter (M4.5, D-105, D-110).
 *
 * **Two capabilities, two questions.** Reading the list answers to
 * `*.matters.parties.view`; adding, correcting, and removing answer to
 * `*.matters.parties.manage`. Neither implies the other, and `*.matters.update`
 * reaches neither — the registry defines separate codes and this controller
 * honours them separately.
 *
 * **The domain comes from the route, never from the row** (D-101). Every action
 * reads it through {@see ResolvesMatterDomain} and passes it explicitly to the
 * Policy, so `/api/v1/notary/…` authorizes through `notary.matters.parties.*`
 * and `/api/v1/ppat/…` through `ppat.matters.parties.*`. The Matter itself is
 * resolved **domain-constrained**, so a Matter of the other domain answers 404
 * rather than 403: a 403 would confirm the record exists in a domain the caller
 * never named.
 *
 * **Managing participation is not authority to discover Parties.** Every path
 * that names a Party — the candidate list and the link body alike — resolves it
 * through {@see ParticipantVisibility}, which applies the Party-domain
 * permission for that Party's own subtype at that subtype's own Data Scope. A
 * submitted `party_id` is re-resolved through that same authorized query rather
 * than trusted, so an id obtained elsewhere cannot become a participation.
 *
 * The nested binding is resolved against the parent Matter explicitly, so a
 * participation belonging to a different Matter cannot be updated or removed
 * through this one's address.
 */
class MatterPartyController extends Controller
{
    use ResolvesMatterDomain;

    public function __construct(
        private readonly ParticipantVisibility $participants,
    ) {}

    /**
     * The Parties taking part in this Matter.
     *
     * Current state, not history: `matter_parties` has no period columns and this
     * returns exactly the rows that exist (D-105).
     *
     * Party visibility is computed in **bulk** — two queries at most, one per
     * subtype branch — rather than per row. The actor's effective access does not
     * vary by row, so a per-row check would be the N+1 M2.6 measured on the Party
     * reverse view and M3.4 avoided by construction.
     */
    public function index(Request $request, string $matter): JsonResponse
    {
        $domain = $this->matterDomain($request);
        $subject = $this->resolveMatter($domain, $matter);

        $this->authorize('viewAny', [MatterParty::class, $subject, $domain]);

        $participations = MatterParty::query()
            ->where('matter_id', $subject->getKey())
            // Archived Parties are loaded deliberately: a participation the
            // office recorded stays listed after the Party is retired.
            ->with(['party' => fn ($query) => $query->withTrashed()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $parties = $participations->pluck('party')->filter()->all();
        $viewable = $this->participants->viewableKeys($parties, $request->user());

        // A Policy ability, never a permission code — it runs the same
        // MatterPartyPolicy -> EffectiveAccessResolver -> Data Scope chain the
        // mutation endpoints authorize through, so the flag cannot disagree with
        // what those endpoints would accept. Presentation only; each of them
        // authorizes again (CLAUDE.md sections 24 and 28).
        //
        // Evaluated once for the whole list rather than per row: participation
        // authority is a property of the parent Matter, which does not vary
        // between rows.
        $canManage = $request->user()->can('manage', [MatterParty::class, $subject, $domain]);

        return response()->json([
            'data' => $participations->map(fn (MatterParty $participation): array => (
                new MatterPartyResource(
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
     * **Narrow on purpose, and doubly authorized.** `*.matters.parties.manage`
     * over this Matter is necessary but not sufficient: the candidate query
     * additionally applies `parties.view` to Individuals and `companies.view` to
     * Companies, each at its own Data Scope, so this endpoint can never surface a
     * Party the actor could not already reach in the Party directory. No Party
     * permission was widened to populate a picker.
     *
     * Candidates are same-Office as the **Matter** and not archived. The first
     * because a cross-office participation is unrepresentable anyway and offering
     * one would be an existence oracle for another Office's directory; the second
     * because a retired record should not be picked for new work.
     *
     * **Nothing here reads the parent Project's participants.** Matter
     * participation is independent of Project participation (D-105); offering the
     * Project's list as a shortcut would be the first step toward the two tables
     * mirroring each other.
     */
    public function options(Request $request, string $matter): JsonResponse
    {
        $domain = $this->matterDomain($request);
        $subject = $this->resolveMatter($domain, $matter);

        $this->authorize('manage', [MatterParty::class, $subject, $domain]);

        $query = $this->participants->candidateQuery($request->user(), $subject->office_id);

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
     * Link a Party to this Matter.
     *
     * The submitted `party_id` is re-resolved through the authorized candidate
     * query. A Party that is in another Office, archived, of a subtype the actor
     * cannot see, or simply nonexistent produces one indistinguishable 422 —
     * telling them apart would answer a question the caller has no permission to
     * ask.
     */
    public function store(
        StoreMatterPartyRequest $request,
        string $matter,
        AddMatterParty $add,
    ): JsonResponse {
        $domain = $this->matterDomain($request);
        $subject = $this->resolveMatter($domain, $matter);

        $this->authorize('manage', [MatterParty::class, $subject, $domain]);

        $party = $this->participants->resolveCandidate(
            $request->user(),
            $subject->office_id,
            $request->validated('party_id'),
        );

        if ($party === null) {
            abort(422, 'Select a Party from this Matter\'s office.');
        }

        $participation = $add->handle(
            $request->user(),
            $subject,
            $party,
            $request->participationAttributes(),
        );

        return $this->resource($request, $participation)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Correct a participation's role or notes.
     *
     * Never its endpoints — the request refuses `party_id`, `matter_id`, and
     * `office_id`, and the model withholds all three from mass assignment.
     */
    public function update(
        UpdateMatterPartyRequest $request,
        string $matter,
        string $matterParty,
        UpdateMatterParty $updateParticipation,
    ): JsonResource {
        $domain = $this->matterDomain($request);
        $subject = $this->resolveMatter($domain, $matter);

        $this->authorize('manage', [MatterParty::class, $subject, $domain]);

        $participation = $updateParticipation->handle(
            $this->resolveParticipation($subject, $matterParty),
            $request->participationAttributes(),
        );

        return $this->resource($request, $participation);
    }

    /**
     * Unlink a Party. Deletes the relationship row and nothing else.
     */
    public function destroy(
        Request $request,
        string $matter,
        string $matterParty,
        RemoveMatterParty $remove,
    ): Response {
        $domain = $this->matterDomain($request);
        $subject = $this->resolveMatter($domain, $matter);

        $this->authorize('manage', [MatterParty::class, $subject, $domain]);

        $remove->handle($this->resolveParticipation($subject, $matterParty));

        return response()->noContent();
    }

    /**
     * Resolve a participation that genuinely belongs to this Matter, or 404.
     *
     * The Matter constraint is in the query rather than checked afterwards, and a
     * foreign participation answers 404 rather than 403: a 403 would confirm the
     * row exists and belongs to a Matter the caller cannot name, which is exactly
     * what nested binding must not leak.
     */
    private function resolveParticipation(Matter $matter, string $participationId): MatterParty
    {
        $participation = MatterParty::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($participationId)
            ->first();

        if ($participation === null) {
            throw (new ModelNotFoundException)->setModel(MatterParty::class, [$participationId]);
        }

        return $participation;
    }

    private function resource(Request $request, MatterParty $participation): MatterPartyResource
    {
        $participation->load(['party' => fn ($query) => $query->withTrashed()]);

        $viewable = $participation->party === null
            ? []
            : $this->participants->viewableKeys([$participation->party], $request->user());

        return new MatterPartyResource(
            $participation,
            ['can_manage' => true],
            isset($viewable[$participation->party_id]),
        );
    }
}
