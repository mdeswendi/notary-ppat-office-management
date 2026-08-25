<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Ppat\Actions\ApprovePpatDeed;
use App\Domains\Ppat\Actions\CreatePpatDeed;
use App\Domains\Ppat\Actions\FinalizePpatDeed;
use App\Domains\Ppat\Actions\RecordPpatDeedNumber;
use App\Domains\Ppat\Actions\ReviewPpatDeed;
use App\Domains\Ppat\Actions\UpdatePpatDeed;
use App\Domains\Ppat\Enums\PpatDeedStatus;
use App\Domains\Ppat\PpatDeedVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ppat\RecordDeedNumberRequest;
use App\Http\Requests\Ppat\StorePpatDeedRequest;
use App\Http\Requests\Ppat\UpdatePpatDeedRequest;
use App\Http\Resources\PpatDeedResource;
use App\Models\Matter;
use App\Models\PpatDeed;
use App\Policies\PpatDeedPolicy;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * PPAT Deed records (M7.2, D-121).
 *
 * Thin (`CLAUDE.md` section 35): authorize, take validated input, call an Action,
 * return a Resource. Scope rules live in {@see PpatDeedVisibility} and lifecycle
 * rules in the Actions, where both can be read and tested without HTTP.
 *
 * **One domain, one root.** `ppat.deeds.*` is a PPAT-only namespace and a Notarial
 * Deed is a different table with its own controller, so there is no domain parameter
 * to resolve here — the route prefix *is* the namespace. That is D-101's construction
 * with a single case, and it is why a caller holding only `notary.deeds.view` gets a
 * 403 from this controller rather than a Notary answer.
 *
 * **An unreachable deed is a 404, not a 403.** {@see resolveDeed()} looks up through
 * canonical visibility, so a deed the caller may not reach behaves as though nothing
 * is there — the D-098 convention, and the same shape `DocumentController` uses.
 *
 * ## Seven acts, seven capabilities, and one that is absent
 *
 * ```text
 * index, show     ppat.deeds.view
 * store           ppat.deeds.create   + ppat.matters.view on the parent
 * update          ppat.deeds.update
 * review          ppat.deeds.review
 * approve         ppat.deeds.approve
 * finalize        ppat.deeds.finalize
 * recordNumber    ppat.deeds.number
 * ```
 *
 * **There is no `DELETE`, and it is not an omission.** The M7.2 brief asked for one
 * *"hanya jika permission `ppat.deeds.delete` ada di registry"*. It is not: the
 * canonical catalogue of 177 codes has no `ppat.deeds.delete`, no `.void` and no
 * `.lock`, so the brief's own condition rules the endpoint out. Three further sources
 * agree separately — `ppat_deeds` has no `deleted_at` column (M7.1, matching the
 * ERD), `03_DATABASE_ERD.md` section 33 prefers states over destructive deletion for
 * finalized legal records, and `CLAUDE.md` section 30 forbids user-facing hard delete
 * of Deeds. A deed recorded in error is a correction mechanism, which is open
 * question nine (D-121, O-039).
 *
 * **`approve` is not restricted to a role name.** The brief specified *"hanya
 * PRINCIPAL/SUPER_ADMIN"*; that is the authorization shape D-032, D-041 and D-048
 * forbid. Restricting approval to the Principal is done by granting
 * `ppat.deeds.approve` to that role alone through the Permission Matrix — office
 * configuration, not a check in code. No role name appears anywhere here.
 */
class PpatDeedController extends Controller
{
    /**
     * The mutation abilities the interface asks about, and the canonical capability
     * each answers to.
     *
     * Resolved in bulk by {@see capabilityMap()} rather than per row — the same
     * construction `DocumentController` and `MatterController` use, and for the same
     * reason: the actor's effective access does not vary by row, so a page of deeds
     * costs one resolver call and one scoped query per capability instead of five per
     * deed.
     */
    private const CAPABILITIES = [
        'can_update' => 'ppat.deeds.update',
        'can_review' => 'ppat.deeds.review',
        'can_approve' => 'ppat.deeds.approve',
        'can_finalize' => 'ppat.deeds.finalize',
        'can_record_number' => 'ppat.deeds.number',
    ];

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PpatDeedVisibility $visibility,
        private readonly MatterVisibility $matters,
    ) {}

    /**
     * Deeds the caller may see.
     *
     * Visibility is applied **in the query**, so a scoped caller's SQL never selects
     * a row they may not open — the pagination total counts only what they may see,
     * and no filter can widen it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PpatDeed::class);

        $actor = $request->user();

        $query = $this->visibility->scope(
            PpatDeed::query()->with(['office', 'matter']),
            $actor,
            $this->resolver->resolve($actor, 'ppat.deeds.view'),
        );

        $this->applyFilters($query, $request);

        $query->orderByDesc('created_at')->orderBy('id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->paginate($perPage)->withQueryString();

        $capabilities = $this->capabilityMap(collect($page->items()), $request);

        return PpatDeedResource::collection($page->through(
            fn (PpatDeed $deed): PpatDeedResource => new PpatDeedResource(
                $deed,
                $capabilities[$deed->getKey()] ?? []
            )
        ));
    }

    public function show(Request $request, string $deed): PpatDeedResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('view', $record);

        return new PpatDeedResource(
            $record->load([
                'office', 'matter',
                'finalDocument',
                'reviewer', 'approver', 'finalizer',
            ]),
            $this->capabilitiesFor($record),
        );
    }

    /**
     * Record a deed against a Matter.
     *
     * The parent is resolved **through canonical Matter visibility** rather than a
     * bare lookup, so an unreachable Matter is indistinguishable from a nonexistent
     * one and this endpoint never becomes a way to discover which Matters exist. The
     * Policy then judges the whole question — the create capability, an eligible
     * scope, the parent's reachability under `ppat.matters.view`, that it is a
     * PPAT Matter, and that it is in the actor's own Office unless the actor holds
     * `ALL` (D-121).
     */
    public function store(StorePpatDeedRequest $request, CreatePpatDeed $create): JsonResponse
    {
        $actor = $request->user();

        $matter = $this->matters->scope(
            Matter::query()
                ->whereKey($request->matterId())
                ->where('domain', MatterDomain::PPAT->value),
            $actor,
            $this->resolver->resolve($actor, 'ppat.matters.view'),
        )->first();

        // An unreachable, Notary, or nonexistent Matter is one indistinguishable
        // field error, so no request shape turns this endpoint into a probe.
        if ($matter === null) {
            throw ValidationException::withMessages([
                'matter_id' => __('validation.exists', ['attribute' => 'matter id']),
            ]);
        }

        $this->authorize('create', [PpatDeed::class, $matter]);

        $deed = $create->handle($actor, $matter, $request->deedAttributes());

        return (new PpatDeedResource(
            $deed->load(['office', 'matter']),
            $this->capabilitiesFor($deed),
        ))->response()->setStatusCode(201);
    }

    public function update(UpdatePpatDeedRequest $request, string $deed, UpdatePpatDeed $update): PpatDeedResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('update', $record);

        $updated = $update->handle($request->user(), $record, $request->deedAttributes());

        return $this->fresh($updated);
    }

    public function review(Request $request, string $deed, ReviewPpatDeed $review): PpatDeedResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('review', $record);

        return $this->fresh($review->handle($request->user(), $record));
    }

    public function approve(Request $request, string $deed, ApprovePpatDeed $approve): PpatDeedResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('approve', $record);

        return $this->fresh($approve->handle($request->user(), $record));
    }

    public function finalize(Request $request, string $deed, FinalizePpatDeed $finalize): PpatDeedResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('finalize', $record);

        return $this->fresh($finalize->handle($request->user(), $record));
    }

    /**
     * Record the legal number the office assigned.
     *
     * **Uniqueness is checked here rather than in the Form Request**, because a
     * scoped `Rule::unique` needs the deed's Office and the deed is resolved after
     * validation — see {@see RecordDeedNumberRequest}. The caller gets the same 422
     * field error either way.
     *
     * The check ignores the deed itself, so re-recording the number a deed already
     * has is not a false conflict.
     */
    public function recordNumber(
        RecordDeedNumberRequest $request,
        string $deed,
        RecordPpatDeedNumber $record,
    ): PpatDeedResource {
        $found = $this->resolveDeed($deed);

        $this->authorize('recordNumber', $found);

        $number = $request->deedNumber();

        $taken = PpatDeed::query()
            ->where('office_id', $found->office_id)
            ->where('deed_number', $number)
            ->whereKeyNot($found->getKey())
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'deed_number' => __('validation.unique', ['attribute' => 'deed number']),
            ]);
        }

        return $this->fresh($record->handle($request->user(), $found, $number));
    }

    /**
     * The vocabulary the interface renders, and the Matters a deed may be recorded
     * against.
     *
     * **Only the four reachable statuses are offered.** `VOID` and `SUPERSEDED` are
     * canonical vocabulary no code path produces (D-121 section 5.1), so listing them
     * here would invite a filter that always returns nothing and a control that
     * cannot work.
     *
     * The Matter list is scoped by `ppat.matters.view` **and** narrowed to the
     * actor's own Office, because that is what {@see PpatDeedPolicy::create()}
     * will accept — offering a Matter that creation would refuse is a dead control.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PpatDeed::class);

        $actor = $request->user();

        $matters = $this->matters->scope(
            Matter::query()
                ->where('domain', MatterDomain::PPAT->value)
                ->where('office_id', $actor->office_id),
            $actor,
            $this->resolver->resolve($actor, 'ppat.matters.view'),
        )
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'matter_number', 'title']);

        return response()->json([
            'data' => [
                'statuses' => array_map(
                    static fn (PpatDeedStatus $status): string => $status->value,
                    PpatDeedStatus::reachable(),
                ),
                'matters' => $matters->map(fn (Matter $matter): array => [
                    'id' => $matter->id,
                    'matter_number' => $matter->matter_number,
                    'title' => $matter->title,
                ])->all(),
            ],
        ]);
    }

    /**
     * Find a Deed the caller may reach, or 404.
     *
     * Resolved **through canonical visibility** rather than a bare lookup, so an
     * unreachable Deed is indistinguishable from a nonexistent one.
     */
    private function resolveDeed(string $deedId): PpatDeed
    {
        $actor = request()->user();

        $record = $this->visibility->scope(
            PpatDeed::query()->whereKey($deedId),
            $actor,
            $this->resolver->resolve($actor, 'ppat.deeds.view'),
        )->first();

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    /**
     * @param  Builder<PpatDeed>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($search = trim((string) $request->query('search', ''))) {
            // Grouped so the search cannot escape the visibility constraint.
            //
            // `deed_number` is searchable because it is what an office actually
            // looks a deed up by. It is not sensitive identity, and the scope
            // predicate still bounds every row. Because numbers are unique only
            // within an Office, one may legitimately match rows in several Offices
            // for an ALL-scoped caller — the search does not pretend otherwise.
            $query->where(function (BuilderContract $inner) use ($search): void {
                $inner->whereLike('title', "%{$search}%")
                    ->orWhereLike('deed_number', "%{$search}%");
            });
        }

        // Unrecognized filter values are ignored rather than erroring: a stale
        // bookmark should show the unfiltered list, not a 422.
        if (PpatDeedStatus::tryFrom((string) $request->query('status', '')) !== null) {
            $query->where('status', $request->query('status'));
        }

        foreach (['matter_id', 'deed_type_code'] as $filter) {
            if (($value = trim((string) $request->query($filter, ''))) !== '') {
                $query->where($filter, $value);
            }
        }

        /*
         * `project_id` resolves through the Matter (O-037).
         *
         * A deed has no `project_id` of its own — it is the output of a Matter, and
         * the Matter names the Project. So this is the one filter that reaches a
         * column the deed does not carry, correlated through `matter_id`.
         *
         * **A filter, not a nested route.** The obvious alternative,
         * `GET /projects/{project}/ppat-deeds`, is the shape D-118 refused for
         * exactly this question: *"A second address for one question is two surfaces
         * that must be kept in step, and the first divergence between them would be
         * a bug."* Documents and Tasks both answer the Project page through
         * `?project_id=`, and deeds now do the same.
         *
         * **It needs no extra authorization, because a filter only narrows.** Every
         * row is already bounded by `ppat.deeds.view` and its Data Scope before
         * this runs, so filtering by a Project the caller cannot open returns the
         * deeds they could already see — never one more. Requiring `projects.view`
         * here would refuse a legitimate narrowing rather than protect anything,
         * and `matter_id` above has always worked the same way.
         */
        if (($projectId = trim((string) $request->query('project_id', ''))) !== '') {
            $query->whereHas('matter', function (Builder $matter) use ($projectId): void {
                $matter->where('project_id', $projectId);
            });
        }

        foreach (['deed_date_from' => '>=', 'deed_date_to' => '<='] as $filter => $operator) {
            $value = trim((string) $request->query($filter, ''));

            if ($value !== '' && strtotime($value) !== false) {
                $query->where('deed_date', $operator, $value);
            }
        }
    }

    /**
     * @return array<string, bool>
     */
    private function capabilitiesFor(PpatDeed $deed): array
    {
        return $this->capabilityMap(collect([$deed]), request())[$deed->getKey()] ?? [];
    }

    /**
     * The capability flags for a page of Deeds, resolved in bulk.
     *
     * The actor's effective access does not vary by row, so it is resolved once per
     * capability and the record predicate asked for every Deed at once — the N+1 M2.6
     * measured on the Party reverse view and every surface since has avoided by
     * construction.
     *
     * **Two adjustments are applied per row afterwards**, both from data the row
     * already carries, so neither costs a query:
     *
     * *Status eligibility is folded in*, so a control the endpoint would answer 422
     * to is absent rather than present and broken — `can_review` false on anything
     * already reviewed, `can_finalize` false on anything not yet approved.
     *
     * *A finalized deed is not updatable*, which mirrors
     * {@see PpatDeedPolicy::update()} rather than duplicating a rule
     * it does not already hold: `CLAUDE.md` sections 29 and 64 make read-only a
     * property of the record.
     *
     * **`can_record_number` is deliberately not folded.**
     * {@see PpatDeedStatus::acceptsNumber()} is true in every state, because tying
     * numbering to a lifecycle position would answer open question five — *"what are
     * the deed numbering rules, and who assigns the number?"*
     *
     * @param  Collection<int, PpatDeed>  $deeds
     * @return array<string, array<string, bool>>
     */
    private function capabilityMap(Collection $deeds, Request $request): array
    {
        if ($deeds->isEmpty()) {
            return [];
        }

        $actor = $request->user();
        $ids = $deeds->map(fn (PpatDeed $deed): string => $deed->getKey())->all();

        $reachable = [];

        foreach (self::CAPABILITIES as $flag => $permission) {
            $reachable[$flag] = $this->visibility->scope(
                PpatDeed::query()->whereIn('id', $ids),
                $actor,
                $this->resolver->resolve($actor, $permission),
            )->pluck('id')->flip();
        }

        $map = [];

        foreach ($deeds as $deed) {
            $key = $deed->getKey();
            $flags = [];

            foreach (self::CAPABILITIES as $flag => $permission) {
                $flags[$flag] = $reachable[$flag]->has($key);
            }

            $flags['can_update'] = $flags['can_update'] && ! $deed->isReadOnly();
            $flags['can_review'] = $flags['can_review'] && $deed->status->isReviewable();
            $flags['can_approve'] = $flags['can_approve'] && $deed->status->isApprovable();
            $flags['can_finalize'] = $flags['can_finalize'] && $deed->status->isFinalizable();

            $map[$key] = $flags;
        }

        return $map;
    }

    private function fresh(PpatDeed $deed): PpatDeedResource
    {
        return new PpatDeedResource(
            $deed->load([
                'office', 'matter',
                'finalDocument',
                'reviewer', 'approver', 'finalizer',
            ]),
            $this->capabilitiesFor($deed),
        );
    }
}
