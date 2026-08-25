<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Ppat\Actions\ArchiveProperty;
use App\Domains\Ppat\Actions\CreateProperty;
use App\Domains\Ppat\Actions\UpdateProperty;
use App\Domains\Ppat\Enums\PropertyType;
use App\Domains\Ppat\PropertyVisibility;
use App\Domains\Ppat\RightTypeExamples;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ppat\StorePropertyRequest;
use App\Http\Requests\Ppat\UpdatePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use App\Policies\PropertyPolicy;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Land objects (M7.3, D-121).
 *
 * Thin (`CLAUDE.md` section 35): authorize, take validated input, call an Action,
 * return a Resource. Scope rules live in {@see PropertyVisibility} and state rules in
 * the Actions, where both can be read and tested without HTTP.
 *
 * ## The route root is `/properties`, not `/ppat/properties`
 *
 * **D-101 says the route decides the permission namespace**, and the canonical
 * capability is `properties.*` — there is no `ppat.properties.*` family in the
 * catalogue. A `/ppat/properties` route would name a namespace that does not exist,
 * so the root matches the codes, exactly as `/documents` and `/parties` do.
 *
 * The *page* lives at `/ppat/properties` and the navigation entry sits in the PPAT
 * group, because `CLAUDE.md` section 16 lists Property among the PPAT-specific
 * concepts. A page path is not a permission namespace, so the two are consistent — but
 * the asymmetry is deliberate rather than an oversight, and is recorded here so nobody
 * "fixes" one to match the other.
 *
 * ## Six acts, six capabilities, and one that is absent
 *
 * ```text
 * index, show, options   properties.view
 * store                  properties.create   (own Office only)
 * update                 properties.update
 * archive                properties.archive
 * ```
 *
 * `properties.ownership.view` and `.update` are the other two, and they live on
 * {@see PropertyOwnerController} because they are a separate surface with a separate
 * audience.
 *
 * **There is no `DELETE`, and it is not an omission.** The M7.3 brief asked for a soft
 * delete; `properties.delete` **is absent from the canonical catalogue** — the same
 * check that ruled out `ppat.deeds.delete` at M7.2 (O-039). What the catalogue does
 * define is `properties.archive`, and `ArchiveProperty` explains why that *is* the
 * soft delete: the ERD gave this table a `deleted_at` and gave the catalogue no delete
 * code, so read together they are one mechanism rather than two dead ones.
 *
 * **An unreachable Property is a 404, not a 403** — {@see resolveProperty()} looks up
 * through canonical visibility, the D-098 convention.
 */
class PropertyController extends Controller
{
    /**
     * The mutation abilities the interface asks about, and the canonical capability
     * each answers to.
     *
     * Resolved in bulk by {@see capabilityMap()} rather than per row — the actor's
     * effective access does not vary by row, so a page costs one resolver call and one
     * scoped query per capability instead of four per Property.
     */
    private const CAPABILITIES = [
        'can_update' => 'properties.update',
        'can_archive' => 'properties.archive',
        'can_view_ownership' => 'properties.ownership.view',
        'can_update_ownership' => 'properties.ownership.update',
    ];

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PropertyVisibility $visibility,
    ) {}

    /**
     * Properties the caller may see.
     *
     * Visibility is applied **in the query**, so a scoped caller's SQL never selects a
     * row they may not open — the pagination total counts only what they may see, and
     * no filter can widen it.
     *
     * **Archived parcels are excluded by default and never hidden.** `?archived=1`
     * shows them and `?archived=all` shows both, because retiring a record from the
     * active list is not the same as making it unfindable — an office looking up an old
     * certificate needs it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Property::class);

        $actor = $request->user();

        $query = $this->visibility->scope(
            Property::query()->with(['office'])->withCount('matters'),
            $actor,
            $this->resolver->resolve($actor, 'properties.view'),
        );

        $this->applyArchivedFilter($query, $request);
        $this->applyFilters($query, $request);

        $showsOwnership = $this->showsOwnership($request);

        if ($showsOwnership) {
            $query->with(['currentOwners.party']);
        }

        $query->orderBy('property_number')->orderBy('id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->paginate($perPage)->withQueryString();

        $capabilities = $this->capabilityMap(collect($page->items()), $request);

        return PropertyResource::collection($page->through(
            fn (Property $property): PropertyResource => new PropertyResource(
                $property,
                $capabilities[$property->getKey()] ?? [],
                $showsOwnership,
            )
        ));
    }

    public function show(Request $request, string $property): PropertyResource
    {
        $record = $this->resolveProperty($property);

        $this->authorize('view', $record);

        $showsOwnership = $this->showsOwnership($request);

        $record->loadCount('matters')
            ->load(['office', 'createdBy', 'updatedBy']);

        if ($showsOwnership) {
            $record->load(['currentOwners.party']);
        }

        return new PropertyResource(
            $record,
            $this->capabilitiesFor($record),
            $showsOwnership,
        );
    }

    /**
     * Record a land object in the actor's own Office.
     *
     * **The Office is never a field.** `PropertyPolicy::create()` delegates to
     * `PropertyVisibility::permitsCreationIn()`, which refuses any destination but the
     * actor's own — including for an actor holding `ALL`, because `ALL` is reach over
     * records that exist rather than authority to place a new one (D-097, D-119).
     */
    public function store(StorePropertyRequest $request, CreateProperty $create): JsonResponse
    {
        $this->authorize('create', Property::class);

        $property = $create->handle($request->user(), $request->propertyAttributes());

        return (new PropertyResource(
            $property->load(['office', 'createdBy', 'updatedBy'])->loadCount('matters'),
            $this->capabilitiesFor($property),
            $this->showsOwnership($request),
        ))->response()->setStatusCode(201);
    }

    /**
     * Correct a Property's own fields.
     *
     * **`PATCH`, not `PUT`.** The brief specified `PUT`; the repository reserves that
     * verb for full replacement — `PUT roles/{role}/permissions` replaces the whole set
     * — and every partial update since M2 is a `PATCH`. This request sends the fields
     * that changed, which is what `PATCH` means.
     *
     * An archived Property is refused by the Policy, so this answers **403** rather
     * than 422: archived-ness is a property of the record, the way `CLAUDE.md`
     * section 29 makes read-only a property of a finalized deed.
     */
    public function update(UpdatePropertyRequest $request, string $property, UpdateProperty $update): PropertyResource
    {
        $record = $this->resolveProperty($property);

        $this->authorize('update', $record);

        $updated = $update->handle($request->user(), $record, $request->propertyAttributes());

        return $this->fresh($updated, $request);
    }

    /**
     * Retire a Property from the office's active reference list.
     *
     * Writes `deleted_at` and **not** `status` — see {@see ArchiveProperty} for why
     * that is the only reading of `properties.archive` that leaves no canonical fact
     * dead.
     *
     * **There is no un-archive.** `properties.restore` does not exist in the catalogue,
     * unlike `projects.restore` (D-093), so M7.3 ships no path back rather than
     * authorizing one through a code that does not name the act (O-045).
     */
    public function archive(Request $request, string $property, ArchiveProperty $archive): PropertyResource
    {
        $record = $this->resolveProperty($property);

        $this->authorize('archive', $record);

        return $this->fresh($archive->handle($request->user(), $record), $request);
    }

    /**
     * The vocabulary the interface renders.
     *
     * **Two lists, treated differently on purpose.** `property_types` is closed — the
     * ERD gives four values flat, and the database CHECKs them, so a `<select>` is
     * honest. `right_type_examples` is **suggestions**: the ERD says *"may use stable
     * machine codes, for example"*, so the interface renders it as a `datalist` over a
     * free-text input and accepts anything typed. A `<select>` there would present six
     * values as the vocabulary of Indonesian land rights, which is a claim nobody in
     * this repository may make (`CLAUDE.md` section 62).
     *
     * `matter_role_examples` is the same shape for `matter_properties.role_code`.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Property::class);

        return response()->json([
            'data' => [
                'property_types' => PropertyType::values(),
                'right_type_examples' => RightTypeExamples::all(),
                'matter_role_examples' => [
                    'TRANSACTION_OBJECT',
                    'COLLATERAL',
                    'RELATED_PROPERTY',
                ],
            ],
        ]);
    }

    /**
     * Find a Property the caller may reach, or 404.
     *
     * **Includes archived rows.** An archived parcel is retired, not unfindable: its
     * detail page must open so an office can read an old certificate, and the Policy
     * refuses the acts that would change it.
     */
    private function resolveProperty(string $propertyId): Property
    {
        $actor = request()->user();

        $record = $this->visibility->scope(
            Property::query()->withTrashed()->whereKey($propertyId),
            $actor,
            $this->resolver->resolve($actor, 'properties.view'),
        )->first();

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    /**
     * @param  Builder<Property>  $query
     */
    private function applyArchivedFilter(Builder $query, Request $request): void
    {
        $archived = strtolower(trim((string) $request->query('archived', '')));

        if ($archived === 'all') {
            $query->withTrashed();

            return;
        }

        if (in_array($archived, ['1', 'true', 'yes', 'only'], true)) {
            $query->onlyTrashed();
        }

        // Default: the active list. `SoftDeletes` already excludes trashed rows.
    }

    /**
     * @param  Builder<Property>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($search = trim((string) $request->query('search', ''))) {
            // Grouped so the search cannot escape the visibility constraint.
            //
            // `certificate_number` is searchable because it is what an office actually
            // looks a parcel up by, and it is not sensitive identity — a certificate
            // number identifies land, not a person (D-082 concerns NIK and NPWP).
            $query->where(function (BuilderContract $inner) use ($search): void {
                $inner->whereLike('property_number', "%{$search}%")
                    ->orWhereLike('certificate_number', "%{$search}%")
                    ->orWhereLike('address', "%{$search}%");
            });
        }

        // Unrecognized values are ignored rather than erroring: a stale bookmark
        // should show the unfiltered list, not a 422.
        if (PropertyType::tryFrom((string) $request->query('property_type', '')) !== null) {
            $query->where('property_type', $request->query('property_type'));
        }

        foreach (['right_type', 'city', 'district', 'province', 'village'] as $filter) {
            if (($value = trim((string) $request->query($filter, ''))) !== '') {
                $query->where($filter, $value);
            }
        }

        if (($certificate = trim((string) $request->query('certificate_number', ''))) !== '') {
            $query->where('certificate_number', $certificate);
        }

        /*
         * `owner_party_id` correlates through the chain of title.
         *
         * **Current holders only**, which is what "properties this party owns" means.
         * The whole chain is `GET /properties/{property}/owners`, and asking there
         * requires `properties.ownership.view` — this filter narrows a list the caller
         * may already see and reveals nothing new about who owns what, because a
         * matching parcel is one they could have found by paging.
         */
        if (($partyId = trim((string) $request->query('owner_party_id', ''))) !== '') {
            $query->whereHas('currentOwners', function (Builder $owner) use ($partyId): void {
                $owner->where('party_id', $partyId);
            });
        }

        /*
         * `matter_id` and `project_id` correlate through `matter_properties`.
         *
         * The O-037 shape, third application. A Property has no `project_id` of its
         * own — it is reference data that predates every Matter naming it — so the
         * Project filter reaches two junctions deep: `matter_properties` to `matters`
         * to `project_id`.
         *
         * **Filters, not nested routes.** `GET /projects/{project}/properties` is the
         * shape D-118 refused for exactly this question, and Documents, Tasks and both
         * deed families already answer their Project page through `?project_id=`.
         *
         * **Neither needs extra authorization, because a filter only narrows.** Every
         * row is already bounded by `properties.view` and its Data Scope before this
         * runs, so filtering by a Matter or Project the caller cannot open returns the
         * Properties they could already see — never one more.
         */
        if (($matterId = trim((string) $request->query('matter_id', ''))) !== '') {
            $query->whereHas('matters', function (Builder $matter) use ($matterId): void {
                $matter->where('matters.id', $matterId);
            });
        }

        if (($projectId = trim((string) $request->query('project_id', ''))) !== '') {
            $query->whereHas('matters', function (Builder $matter) use ($projectId): void {
                $matter->where('matters.project_id', $projectId);
            });
        }
    }

    /**
     * Whether this caller may see who owns what.
     *
     * `properties.ownership.view` is its own canonical capability, so the owner columns
     * are present for a holder and absent otherwise — reading a parcel is not reading
     * its chain of title.
     */
    private function showsOwnership(Request $request): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($request->user(), 'properties.ownership.view')
        );
    }

    /**
     * @return array<string, bool>
     */
    private function capabilitiesFor(Property $property): array
    {
        return $this->capabilityMap(collect([$property]), request())[$property->getKey()] ?? [];
    }

    /**
     * The capability flags for a page of Properties, resolved in bulk.
     *
     * The actor's effective access does not vary by row, so it is resolved once per
     * capability and the record predicate asked for every Property at once — the N+1
     * every surface since M2.6 has avoided by construction.
     *
     * **One adjustment is applied per row afterwards**, from data the row already
     * carries so it costs no query: an archived Property is neither updatable nor
     * archivable again, which mirrors {@see PropertyPolicy} rather than duplicating a
     * rule it does not hold.
     *
     * @param  Collection<int, Property>  $properties
     * @return array<string, array<string, bool>>
     */
    private function capabilityMap(Collection $properties, Request $request): array
    {
        if ($properties->isEmpty()) {
            return [];
        }

        $actor = $request->user();
        $ids = $properties->map(fn (Property $property): string => $property->getKey())->all();

        $reachable = [];

        foreach (self::CAPABILITIES as $flag => $permission) {
            $reachable[$flag] = $this->visibility->scope(
                Property::query()->withTrashed()->whereIn('id', $ids),
                $actor,
                $this->resolver->resolve($actor, $permission),
            )->pluck('id')->flip();
        }

        $map = [];

        foreach ($properties as $property) {
            $key = $property->getKey();
            $flags = [];

            foreach (self::CAPABILITIES as $flag => $permission) {
                $flags[$flag] = $reachable[$flag]->has($key);
            }

            $archived = $property->deleted_at !== null;

            $flags['can_update'] = $flags['can_update'] && ! $archived;
            $flags['can_archive'] = $flags['can_archive'] && ! $archived;
            $flags['can_update_ownership'] = $flags['can_update_ownership'] && ! $archived;

            $map[$key] = $flags;
        }

        return $map;
    }

    private function fresh(Property $property, Request $request): PropertyResource
    {
        $showsOwnership = $this->showsOwnership($request);

        $property->loadCount('matters')->load(['office', 'createdBy', 'updatedBy']);

        if ($showsOwnership) {
            $property->load(['currentOwners.party']);
        }

        return new PropertyResource(
            $property,
            $this->capabilitiesFor($property),
            $showsOwnership,
        );
    }
}
