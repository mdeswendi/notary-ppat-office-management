<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Notary\Actions\ApproveNotaryDeed;
use App\Domains\Notary\Actions\CreateNotaryDeed;
use App\Domains\Notary\Actions\FinalizeNotaryDeed;
use App\Domains\Notary\Actions\RecordNotaryDeedNumber;
use App\Domains\Notary\Actions\ReviewNotaryDeed;
use App\Domains\Notary\Actions\UpdateNotaryDeed;
use App\Domains\Notary\Enums\NotaryDeedStatus;
use App\Domains\Notary\NotaryDeedVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notary\RecordDeedNumberRequest;
use App\Http\Requests\Notary\StoreNotaryDeedRequest;
use App\Http\Requests\Notary\UpdateNotaryDeedRequest;
use App\Http\Resources\NotaryDeedResource;
use App\Models\Matter;
use App\Models\NotaryDeed;
use App\Policies\NotaryDeedPolicy;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Notarial Deed records (M6.2, D-120).
 *
 * Thin (`CLAUDE.md` section 35): authorize, take validated input, call an Action,
 * return a Resource. Scope rules live in {@see NotaryDeedVisibility} and lifecycle
 * rules in the Actions, where both can be read and tested without HTTP.
 *
 * **One domain, one root.** Unlike Matter there is no `/ppat/deeds` — `notary.deeds.*`
 * is a Notary-only namespace and PPAT deeds are a different table in a different
 * milestone. So there is no domain parameter to resolve; the route prefix is the
 * namespace, which is still D-101's construction with only one case.
 *
 * **An unreachable deed is a 404, not a 403.** {@see resolveDeed()} looks up through
 * canonical visibility, so a deed the caller may not reach behaves as though nothing
 * is there — the D-098 convention, and the same shape `DocumentController` uses.
 *
 * ## Seven acts, seven capabilities, and one that is absent
 *
 * ```text
 * index, show     notary.deeds.view
 * store           notary.deeds.create   + notary.matters.view on the parent
 * update          notary.deeds.update
 * review          notary.deeds.review
 * approve         notary.deeds.approve
 * finalize        notary.deeds.finalize
 * recordNumber    notary.deeds.number
 * ```
 *
 * **There is no `DELETE`, and it is not an omission.** The M6.2 brief asked for a
 * soft delete restricted to `DRAFT`. `notary_deeds` has no `deleted_at` column
 * (M6.1), the canonical catalogue has no `notary.deeds.delete` code, and the brief
 * itself forbids both a migration and a new permission — so its own constraints rule
 * the endpoint out. Four canonical sources agree separately: the ERD omits the
 * column, `03_DATABASE_ERD.md` section 33 prefers states over destructive deletion
 * for finalized legal records, and `CLAUDE.md` section 30 forbids user-facing hard
 * delete of Deeds. A deed recorded in error is a correction mechanism, which is open
 * question five (D-120).
 *
 * **`approve` is not restricted to a role name.** The brief specified *"hanya
 * PRINCIPAL/SUPER_ADMIN"*; that is the authorization shape D-032, D-041 and D-048
 * forbid. Restricting approval to the Principal is done by granting
 * `notary.deeds.approve` to that role alone through the Permission Matrix — office
 * configuration, not a check in code. No role name appears anywhere here.
 */
class NotaryDeedController extends Controller
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
        'can_update' => 'notary.deeds.update',
        'can_review' => 'notary.deeds.review',
        'can_approve' => 'notary.deeds.approve',
        'can_finalize' => 'notary.deeds.finalize',
        'can_record_number' => 'notary.deeds.number',
    ];

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly NotaryDeedVisibility $visibility,
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
        $this->authorize('viewAny', NotaryDeed::class);

        $actor = $request->user();

        $query = $this->visibility->scope(
            NotaryDeed::query()->with(['office', 'matter']),
            $actor,
            $this->resolver->resolve($actor, 'notary.deeds.view'),
        );

        $this->applyFilters($query, $request);

        $query->orderByDesc('created_at')->orderBy('id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->paginate($perPage)->withQueryString();

        $capabilities = $this->capabilityMap(collect($page->items()), $request);

        return NotaryDeedResource::collection($page->through(
            fn (NotaryDeed $deed): NotaryDeedResource => new NotaryDeedResource(
                $deed,
                $capabilities[$deed->getKey()] ?? []
            )
        ));
    }

    public function show(Request $request, string $deed): NotaryDeedResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('view', $record);

        return new NotaryDeedResource(
            $record->load([
                'office', 'matter',
                'draftDocument', 'finalDocument', 'minutaDocument',
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
     * scope, the parent's reachability under `notary.matters.view`, that it is a
     * NOTARY Matter, and that it is in the actor's own Office unless the actor holds
     * `ALL` (D-120).
     */
    public function store(StoreNotaryDeedRequest $request, CreateNotaryDeed $create): JsonResponse
    {
        $actor = $request->user();

        $matter = $this->matters->scope(
            Matter::query()
                ->whereKey($request->matterId())
                ->where('domain', MatterDomain::NOTARY->value),
            $actor,
            $this->resolver->resolve($actor, 'notary.matters.view'),
        )->first();

        // An unreachable, PPAT, or nonexistent Matter is one indistinguishable
        // field error, so no request shape turns this endpoint into a probe.
        if ($matter === null) {
            throw ValidationException::withMessages([
                'matter_id' => __('validation.exists', ['attribute' => 'matter id']),
            ]);
        }

        $this->authorize('create', [NotaryDeed::class, $matter]);

        $deed = $create->handle($actor, $matter, $request->deedAttributes());

        return (new NotaryDeedResource(
            $deed->load(['office', 'matter']),
            $this->capabilitiesFor($deed),
        ))->response()->setStatusCode(201);
    }

    public function update(UpdateNotaryDeedRequest $request, string $deed, UpdateNotaryDeed $update): NotaryDeedResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('update', $record);

        $updated = $update->handle($request->user(), $record, $request->deedAttributes());

        return $this->fresh($updated);
    }

    public function review(Request $request, string $deed, ReviewNotaryDeed $review): NotaryDeedResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('review', $record);

        return $this->fresh($review->handle($request->user(), $record));
    }

    public function approve(Request $request, string $deed, ApproveNotaryDeed $approve): NotaryDeedResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('approve', $record);

        return $this->fresh($approve->handle($request->user(), $record));
    }

    public function finalize(Request $request, string $deed, FinalizeNotaryDeed $finalize): NotaryDeedResource
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
        RecordNotaryDeedNumber $record,
    ): NotaryDeedResource {
        $found = $this->resolveDeed($deed);

        $this->authorize('recordNumber', $found);

        $number = $request->deedNumber();

        $taken = NotaryDeed::query()
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
     * canonical vocabulary no code path produces (D-120), so listing them here would
     * invite a filter that always returns nothing and a control that cannot work.
     *
     * The Matter list is scoped by `notary.matters.view` **and** narrowed to the
     * actor's own Office, because that is what {@see NotaryDeedPolicy::create()}
     * will accept — offering a Matter that creation would refuse is a dead control.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', NotaryDeed::class);

        $actor = $request->user();

        $matters = $this->matters->scope(
            Matter::query()
                ->where('domain', MatterDomain::NOTARY->value)
                ->where('office_id', $actor->office_id),
            $actor,
            $this->resolver->resolve($actor, 'notary.matters.view'),
        )
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'matter_number', 'title']);

        return response()->json([
            'data' => [
                'statuses' => array_map(
                    static fn (NotaryDeedStatus $status): string => $status->value,
                    NotaryDeedStatus::reachable(),
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
    private function resolveDeed(string $deedId): NotaryDeed
    {
        $actor = request()->user();

        $record = $this->visibility->scope(
            NotaryDeed::query()->whereKey($deedId),
            $actor,
            $this->resolver->resolve($actor, 'notary.deeds.view'),
        )->first();

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    /**
     * @param  Builder<NotaryDeed>  $query
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
        if (NotaryDeedStatus::tryFrom((string) $request->query('status', '')) !== null) {
            $query->where('status', $request->query('status'));
        }

        foreach (['matter_id', 'deed_type_code'] as $filter) {
            if (($value = trim((string) $request->query($filter, ''))) !== '') {
                $query->where($filter, $value);
            }
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
    private function capabilitiesFor(NotaryDeed $deed): array
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
     * {@see NotaryDeedPolicy::update()} rather than duplicating a rule
     * it does not already hold: `CLAUDE.md` sections 29 and 64 make read-only a
     * property of the record.
     *
     * **`can_record_number` is deliberately not folded.**
     * {@see NotaryDeedStatus::acceptsNumber()} is true in every state, because tying
     * numbering to a lifecycle position would answer open question one — *"who
     * assigns the number, and when?"*
     *
     * @param  Collection<int, NotaryDeed>  $deeds
     * @return array<string, array<string, bool>>
     */
    private function capabilityMap(Collection $deeds, Request $request): array
    {
        if ($deeds->isEmpty()) {
            return [];
        }

        $actor = $request->user();
        $ids = $deeds->map(fn (NotaryDeed $deed): string => $deed->getKey())->all();

        $reachable = [];

        foreach (self::CAPABILITIES as $flag => $permission) {
            $reachable[$flag] = $this->visibility->scope(
                NotaryDeed::query()->whereIn('id', $ids),
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

    private function fresh(NotaryDeed $deed): NotaryDeedResource
    {
        return new NotaryDeedResource(
            $deed->load([
                'office', 'matter',
                'draftDocument', 'finalDocument', 'minutaDocument',
                'reviewer', 'approver', 'finalizer',
            ]),
            $this->capabilitiesFor($deed),
        );
    }
}
