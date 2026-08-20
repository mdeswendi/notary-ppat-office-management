<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Matter\Actions\CreateMatter;
use App\Domains\Matter\Actions\UpdateMatter;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Project\ProjectVisibility;
use App\Http\Controllers\Api\V1\Concerns\ResolvesMatterDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matter\StoreMatterRequest;
use App\Http\Requests\Matter\UpdateMatterRequest;
use App\Http\Resources\MatterResource;
use App\Models\Matter;
use App\Models\Project;
use App\Models\ServiceType;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Matter records (M4.4, D-109).
 *
 * Thin (CLAUDE.md section 35): authorize, take validated input, call an Action,
 * return a Resource. Scope rules live in {@see MatterVisibility} and mutation
 * rules in the Actions, where both can be read and tested without HTTP.
 *
 * **One controller serves both domains, and the domain comes from the route.**
 * `/api/v1/notary/matters` and `/api/v1/ppat/matters` bind the same class with a
 * different `MatterDomain`, which selects the permission namespace (D-101). The
 * domain is never read from the request body and never inferred from the record
 * being addressed — {@see domain()} reads the route, and every Policy call is
 * given the result explicitly.
 *
 * **A Matter of the other domain is a 404, not a 403.** {@see resolveMatter()}
 * constrains the lookup by domain, so a Notary address handed a PPAT id answers
 * as though nothing is there — a 403 would confirm the record exists in a domain
 * the caller did not name. That is the D-098 nested-binding convention, applied
 * to a domain root instead of a parent id.
 *
 * **Deliberately absent, each for its own reason:** assignment, which has its own
 * capability and controller (D-091's discipline); completion and cancellation,
 * likewise; stage movement, which has no workflow to move (D-104) and stays
 * unreachable until M4.7; and archive or restore, which M4 does not have at all —
 * `matters.deleted_at` is reserved schema capability with no lifecycle, and no
 * canonical code could authorize one (D-102). There is **no `DELETE`**.
 *
 * **`*.matters.view_all` is not consulted anywhere in this class.** It is
 * superseded by Data Scope `ALL` for reach (D-090), and a second reach mechanism
 * is exactly what must not exist.
 */
class MatterController extends Controller
{
    use ResolvesMatterDomain;

    /**
     * The mutation abilities the interface asks about, and the capability suffix
     * each one answers to. The domain prefix is added per request.
     */
    private const CAPABILITIES = [
        'can_update' => 'update',
        'can_assign' => 'assign',
        'can_complete' => 'complete',
        'can_cancel' => 'cancel',

        // Participation (M4.5, D-105). Two flags rather than one, because the two
        // codes are independent and `manage` does not imply `view` — an interface
        // told only "can manage" would have to guess whether to render the list it
        // is offering to edit. Both resolve through the same bulk path as the
        // others, so a Matter list costs no extra query for carrying them.
        'can_view_parties' => 'parties.view',
        'can_manage_parties' => 'parties.manage',
    ];

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly MatterVisibility $visibility,
        private readonly ProjectVisibility $projects,
    ) {}

    /**
     * Matters of this domain that the caller may see.
     *
     * Domain isolation is applied **first and in the query**, so a Notary list
     * never selects a PPAT row for any caller at any scope. Visibility is applied
     * in the same query, so a scoped caller's SQL never selects a row they may
     * not open — the pagination total counts only what they may see, and no
     * filter can widen it.
     *
     * **Matter Data Scope is applied to the Matter**, never through the parent
     * Project: reaching a Project confers no Matter access (D-100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $domain = $this->matterDomain($request);

        $this->authorize('viewAny', [Matter::class, $domain]);

        $actor = $request->user();

        $query = $this->visibility->scope(
            Matter::query()
                ->where('domain', $domain->value)
                ->with(['office', 'project', 'serviceType', 'picUser']),
            $actor,
            $this->resolver->resolve($actor, $domain->permission('view')),
        );

        if ($search = trim((string) $request->query('search', ''))) {
            // Grouped so the search cannot escape the visibility or domain
            // constraint.
            //
            // `matter_number` is searchable and safe: it is ordinary office
            // identification rather than sensitive identity, and the scope
            // predicate still bounds every row. Because references are unique
            // only within an Office (D-103), one reference may legitimately match
            // rows in several Offices for an ALL-scoped caller — the search does
            // not pretend otherwise.
            $query->where(function (BuilderContract $inner) use ($search): void {
                $inner->whereLike('title', "%{$search}%")
                    ->orWhereLike('matter_number', "%{$search}%");
            });
        }

        // Unrecognized filter values are ignored rather than erroring: a stale
        // bookmark should show the unfiltered list, not a 422.
        if (MatterStatus::tryFrom((string) $request->query('status', '')) !== null) {
            $query->where('status', $request->query('status'));
        }

        if (ProjectPriority::tryFrom((string) $request->query('priority', '')) !== null) {
            $query->where('priority', $request->query('priority'));
        }

        foreach (['project_id', 'service_type_id', 'pic_user_id'] as $filter) {
            if (($value = trim((string) $request->query($filter, ''))) !== '') {
                $query->where($filter, $value);
            }
        }

        $query->orderByDesc('created_at')->orderBy('id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->paginate($perPage)->withQueryString();

        $capabilities = $this->capabilityMap(collect($page->items()), $request, $domain);

        return MatterResource::collection($page->through(
            fn (Matter $matter): MatterResource => new MatterResource(
                $matter,
                $capabilities[$matter->getKey()] ?? []
            )
        ));
    }

    /**
     * Open a Matter of this domain.
     */
    public function show(Request $request, string $matter): MatterResource
    {
        $domain = $this->matterDomain($request);
        $record = $this->resolveMatter($domain, $matter);

        $this->authorize('view', [$record, $domain]);

        return new MatterResource(
            $record->load(['office', 'project', 'serviceType', 'picUser']),
            $this->capabilitiesFor($record, $request, $domain),
        );
    }

    /**
     * Create a Matter under a Project.
     *
     * The parent is resolved **through canonical Project visibility** rather than
     * a bare lookup, so an unreachable or archived Project is indistinguishable
     * from a nonexistent one. The Policy then judges the whole question — the
     * domain's create capability, an eligible scope, the parent's reachability
     * under `projects.view`, and the parent being in the actor's own Office even
     * for an `ALL` actor (D-109).
     */
    public function store(StoreMatterRequest $request, CreateMatter $create): JsonResponse
    {
        $domain = $this->matterDomain($request);
        $actor = $request->user();

        $project = $this->projects->scope(
            Project::query()->whereKey($request->projectId()),
            $actor,
            $this->resolver->resolve($actor, 'projects.view'),
        )->first();

        if ($project === null) {
            abort(422, 'Select a Project you can open in your own Office.');
        }

        $this->authorize('create', [Matter::class, $domain, $project]);

        $serviceTypeId = $this->resolveServiceType($request->serviceTypeId(), $project->office_id, $domain);

        $matter = $create->handle($actor, $project, $domain, $request->matterAttributes());

        if ($serviceTypeId !== null) {
            $matter->service_type_id = $serviceTypeId;
            $matter->save();
        }

        return (new MatterResource(
            $matter->load(['office', 'project', 'serviceType', 'picUser']),
            $this->capabilitiesFor($matter, $request, $domain),
        ))->response()->setStatusCode(201);
    }

    /**
     * Correct a Matter's ordinary attributes.
     *
     * **The parent Project is not re-authorized here.** Matter authority is
     * judged on the Matter (D-100); creation is the one place the parent is
     * consulted, because that is where a parent is being chosen.
     */
    public function update(
        UpdateMatterRequest $request,
        string $matter,
        UpdateMatter $updateMatter,
    ): MatterResource {
        $domain = $this->matterDomain($request);
        $record = $this->resolveMatter($domain, $matter);

        $this->authorize('update', [$record, $domain]);

        $attributes = $request->matterAttributes();

        if ($request->hasServiceType()) {
            $attributes['service_type_id'] = $this->resolveServiceType(
                $request->serviceTypeId(),
                $record->office_id,
                $domain,
            );
        }

        $updated = $updateMatter->handle($request->user(), $record, $attributes);

        return new MatterResource(
            $updated->load(['office', 'project', 'serviceType', 'picUser']),
            $this->capabilitiesFor($updated, $request, $domain),
        );
    }

    /**
     * Service Types this Matter surface may classify work with.
     *
     * **Narrow by construction.** Same Office, same domain, and active — the
     * three conditions the database already enforces for the first two, restated
     * here so a picker never offers something the insert would refuse. Retired
     * entries are excluded from *new* selection while remaining valid on Matters
     * that already reference them (D-106).
     *
     * Authorized by the Matter capability alone. A Service Type is not personal
     * data and the candidate set is already bounded to this actor's own Office and
     * this route's domain, so unlike M3.4's Party candidates no second
     * master-data grant is required — an actor who may create Matters here can see
     * exactly the classifications those Matters may use, and nothing else.
     */
    public function serviceTypeOptions(Request $request): JsonResponse
    {
        $domain = $this->matterDomain($request);

        $this->authorize('viewAny', [Matter::class, $domain]);

        $candidates = ServiceType::query()
            ->where('office_id', $request->user()->office_id)
            ->where('domain', $domain->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name_id')
            ->get(['id', 'code', 'name_id', 'name_en'])
            ->map(fn (ServiceType $type): array => [
                'id' => $type->id,
                'code' => $type->code,
                'name_id' => $type->name_id,
                'name_en' => $type->name_en,
            ])->all();

        return response()->json(['data' => ['service_types' => $candidates]]);
    }

    /**
     * Re-resolve a submitted Service Type through the same conditions the picker
     * applies.
     *
     * **The submitted id is never trusted.** A Service Type from another Office,
     * of the other domain, retired, or simply nonexistent produces one
     * indistinguishable 422 — telling them apart would answer a question the
     * caller has no permission to ask. The database refuses the first two
     * regardless; this exists so the caller gets a field error rather than a 500.
     */
    private function resolveServiceType(?string $serviceTypeId, string $officeId, MatterDomain $domain): ?string
    {
        if ($serviceTypeId === null) {
            return null;
        }

        $exists = ServiceType::query()
            ->whereKey($serviceTypeId)
            ->where('office_id', $officeId)
            ->where('domain', $domain->value)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            abort(422, 'Select an active service type for this office and domain.');
        }

        return $serviceTypeId;
    }

    /**
     * @return array<string, bool>
     */
    private function capabilitiesFor(Matter $matter, Request $request, MatterDomain $domain): array
    {
        return $this->capabilityMap(collect([$matter]), $request, $domain)[$matter->getKey()] ?? [];
    }

    /**
     * The capability flags for a page of Matters, resolved in bulk.
     *
     * The actor's effective access does not vary by row, so it is resolved once
     * per capability and the record predicate asked for every Matter at once —
     * the N+1 M2.6 measured on the Party reverse view and every surface since has
     * avoided by construction.
     *
     * @param  Collection<int, Matter>  $matters
     * @return array<string, array<string, bool>>
     */
    private function capabilityMap(Collection $matters, Request $request, MatterDomain $domain): array
    {
        if ($matters->isEmpty()) {
            return [];
        }

        $actor = $request->user();
        $ids = $matters->map(fn (Matter $matter): string => $matter->getKey())->all();

        $reachable = [];

        foreach (self::CAPABILITIES as $flag => $capability) {
            $reachable[$flag] = $this->visibility->scope(
                Matter::query()->whereIn('id', $ids),
                $actor,
                $this->resolver->resolve($actor, $domain->permission($capability)),
            )->pluck('id')->flip();
        }

        $map = [];

        foreach ($matters as $matter) {
            foreach (self::CAPABILITIES as $flag => $capability) {
                $map[$matter->getKey()][$flag] = $reachable[$flag]->has($matter->getKey());
            }
        }

        return $map;
    }
}
