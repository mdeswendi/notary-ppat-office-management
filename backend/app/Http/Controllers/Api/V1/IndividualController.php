<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Party\Actions\ArchiveIndividual;
use App\Domains\Party\Actions\CreateIndividual;
use App\Domains\Party\Actions\UpdateIndividual;
use App\Domains\Party\PartyVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Party\StoreIndividualRequest;
use App\Http\Requests\Party\UpdateIndividualRequest;
use App\Http\Resources\IndividualResource;
use App\Models\Individual;
use App\Models\Office;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Individual records.
 *
 * Thin (CLAUDE.md section 35): authorize, take validated input, call an Action,
 * return a Resource. The aggregate rules live in the Actions and the scope rules
 * in {@see PartyVisibility}, where both can be read and tested without HTTP.
 *
 * Deliberately absent, and each still absent now that the milestones that own
 * them have shipped: sensitive identity handling, which lives on its own surface
 * under its own permissions; anything Company-shaped (M2.3); and duplicate
 * detection, which is its own advisory controller (M2.5). Also absent: `DELETE`.
 * Party-domain records are archived, never destroyed (D-081), and no restore
 * exists because no restore permission does.
 */
class IndividualController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PartyVisibility $visibility,
    ) {}

    /**
     * Individuals the caller may see.
     *
     * Visibility is applied **in the query**, so an office-scoped caller's SQL
     * never selects another Office's rows — the pagination total counts only what
     * they may see, and no filter can widen it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Individual::class);

        $actor = $request->user();

        $parties = $this->visibility->scope(
            Party::query()->where('party_type', 'INDIVIDUAL'),
            $actor,
            $this->resolver->resolve($actor, 'parties.view'),
        );

        $query = Individual::query()
            ->with(['party.office'])
            ->whereIn('party_id', $parties->select('parties.id'));

        if ($search = trim((string) $request->query('search', ''))) {
            // Grouped so the search cannot escape the visibility constraint.
            //
            // Non-sensitive fields only, and **permanently** so — this is not
            // waiting on M2.5, which has shipped (restated at M2.6, D-077).
            // D-084 settled the rule the exclusion anticipated: a directory that
            // answers "does this NIK exist" is an existence oracle. Identifier
            // matching lives on the duplicate-candidate endpoints, bounded to
            // one Office and gated on that identifier's own full-view permission.
            $query->where(function ($inner) use ($search): void {
                $inner->whereLike('full_name', "%{$search}%")
                    ->orWhereHas('party', function ($party) use ($search): void {
                        $party->whereLike('display_name', "%{$search}%")
                            ->orWhereLike('primary_phone', "%{$search}%")
                            ->orWhereLike('primary_email', "%{$search}%");
                    });
            });
        }

        $query->orderBy('full_name')->orderBy('party_id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        return IndividualResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function store(StoreIndividualRequest $request, CreateIndividual $create): JsonResponse
    {
        // The destination Office is part of the decision, so it is authorized
        // rather than merely validated: an office-scoped actor may create
        // individuals, but only in their own Office.
        $this->authorize('create', [Individual::class, $request->validated('office_id')]);

        $individual = $create->handle(
            $request->user(),
            $request->validated('office_id'),
            $request->partyAttributes(),
            $request->individualAttributes(),
        );

        return IndividualResource::make($individual->load('party.office'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Individual $individual): IndividualResource
    {
        $this->authorize('view', $individual);

        return IndividualResource::make($individual->load('party.office'));
    }

    public function update(
        UpdateIndividualRequest $request,
        Individual $individual,
        UpdateIndividual $update,
    ): IndividualResource {
        $this->authorize('update', $individual);

        $updated = $update->handle(
            $request->user(),
            $individual,
            $request->partyAttributes(),
            $request->individualAttributes(),
        );

        return IndividualResource::make($updated->load('party.office'));
    }

    /**
     * Archive the aggregate. Not a deletion — see {@see ArchiveIndividual}.
     */
    public function archive(Request $request, Individual $individual, ArchiveIndividual $archive): Response
    {
        $this->authorize('archive', $individual);

        $archive->handle($request->user(), $individual);

        return response()->noContent();
    }

    /**
     * The Offices this caller may create an Individual in.
     *
     * Narrow form metadata, not an Office API: it reads nothing else and writes
     * nothing. An office-scoped caller sees only their own Office, so the
     * dropdown cannot offer a destination the Policy would then refuse.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('create', Individual::class);

        $actor = $request->user();
        $access = $this->resolver->resolve($actor, 'parties.create');

        $offices = Office::query()->where('is_active', true);

        // Anything short of ALL is confined to the actor's own Office — the same
        // predicate the Policy applies, so the two cannot disagree.
        if (! $this->visibility->reachesAllOffices($access)) {
            $offices->whereKey($actor->office_id);
        }

        return response()->json([
            'data' => [
                'offices' => $offices->orderBy('name')->get(['id', 'code', 'name'])
                    ->map(fn (Office $office): array => [
                        'id' => $office->id,
                        'code' => $office->code,
                        'name' => $office->name,
                    ])->all(),
            ],
        ]);
    }
}
