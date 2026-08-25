<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Ppat\Actions\SetWarkahStatus;
use App\Domains\Ppat\Actions\StartWarkah;
use App\Domains\Ppat\Actions\VerifyWarkah;
use App\Domains\Ppat\Enums\PpatWarkahStatus;
use App\Domains\Ppat\PpatDeedVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ppat\UpdateWarkahStatusRequest;
use App\Http\Requests\Ppat\VerifyWarkahRequest;
use App\Http\Resources\PpatWarkahResource;
use App\Models\PpatDeed;
use App\Models\PpatWarkah;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Warkah — the supporting documents bound with a PPAT Deed (M7.4, D-121).
 *
 * Thin (`CLAUDE.md` section 35): authorize, take validated input, call an Action,
 * return a Resource. Scope rules live in {@see PpatDeedVisibility} — a Warkah's reach
 * *is* its deed's reach — and the refusals live in the Actions, where each can be read
 * and tested without HTTP.
 *
 * ## Four acts, four capabilities, and two that have no route at all
 *
 * ```text
 * index, show    ppat.warkah.view
 * updateStatus   ppat.warkah.update    INCOMPLETE and UNDER_REVIEW only
 * verify         ppat.warkah.verify    COMPLETE, stamping verified_at/_by
 *
 * finalize       ppat.warkah.finalize  NO ROUTE — registered, unimplemented
 * archive        ppat.warkah.archive   NO ROUTE — registered, unimplemented
 * ```
 *
 * **The last two are the M7.4 brief's own asks, and they are refused.** Both codes are
 * canonical; what is missing is the *trigger*. *"What are the binding/archiving
 * requirements for deeds and supporting Warkah?"* is open question eight, and
 * `09_PPAT_WORKFLOW.md` section 2 says of exactly these obligations that they are
 * *"precisely the kind of rule that must not be reconstructed from memory."* So
 * `FINALIZED` and `ARCHIVED` stay stored vocabulary no code path reaches (D-121
 * section 12), where `notary.minuta.archive` and `.release` also sit (D-064, O-041).
 *
 * ## Reading never starts a bundle
 *
 * `show` answers **404** while no Warkah exists. The brief asked for *"create if not
 * exists"* on the read endpoint; {@see StartWarkah} explains the refusal — a
 * `view` capability that silently writes is one nobody can reason about, and a
 * read-only actor's page load would insert a row. The bundle materialises on the first
 * act of composing it, under `ppat.warkah.update`.
 *
 * The 404 is the M6.3 convention for this exact shape: one of two things the caller
 * cannot tell apart, by design — nothing started, or a deed they may not reach.
 *
 * ## Nothing here is gated on completeness or on a transition matrix
 *
 * The M7 lock section 8.2: *"Status is settable and not gated"*, and *"no completeness
 * percentage gates any deed act."* The capability is the gate — the shape D-102 ruled
 * for `MatterStatus`, whose enum refuses a `canTransitionTo()` in its own docblock.
 */
class PpatWarkahController extends Controller
{
    /**
     * The mutation abilities the interface asks about, and the capability each
     * answers to.
     *
     * Resolved once per request rather than per row: a Warkah's authority is a
     * property of its deed, and the actor's effective access does not vary between
     * bundles beyond what the scope predicate already decides.
     */
    private const CAPABILITIES = [
        'can_manage' => 'ppat.warkah.update',
        'can_verify' => 'ppat.warkah.verify',
        'can_upload' => 'ppat.warkah.upload',
    ];

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PpatDeedVisibility $visibility,
    ) {}

    /**
     * Every Warkah the caller may see.
     *
     * **The one question a top-level surface answers that the deed page cannot**:
     * *which bundles are still short?* Filterable by status and by completeness, so an
     * office can find the transactions whose evidence is not in.
     *
     * Visibility is applied **through the deed**, so a scoped caller's SQL never
     * selects a bundle whose deed they may not open — the pagination total counts only
     * what they may see, and no filter can widen it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PpatWarkah::class);

        $actor = $request->user();

        $reachableDeeds = $this->visibility->scope(
            PpatDeed::query()->select('ppat_deeds.id'),
            $actor,
            $this->resolver->resolve($actor, 'ppat.warkah.view'),
        );

        $query = PpatWarkah::query()
            ->whereIn('ppat_deed_id', $reachableDeeds)
            ->with(['deed', 'verifier'])
            ->withCount('items');

        $this->applyFilters($query, $request);

        $query->orderBy('completeness_percentage')->orderBy('id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->paginate($perPage)->withQueryString();

        $capabilities = $this->capabilityFlags($request);

        return PpatWarkahResource::collection($page->through(
            fn (PpatWarkah $warkah): PpatWarkahResource => new PpatWarkahResource($warkah, $capabilities)
        ));
    }

    /**
     * The bundle for one deed, or 404 while the office has not started one.
     */
    public function show(Request $request, string $deed): PpatWarkahResource
    {
        $record = $this->resolveDeed($deed);

        $this->authorize('view', [PpatWarkah::class, $record]);

        $warkah = PpatWarkah::query()
            ->where('ppat_deed_id', $record->getKey())
            ->with(['deed', 'verifier', 'finalizer'])
            ->withCount('items')
            ->first();

        if ($warkah === null) {
            abort(404);
        }

        return new PpatWarkahResource($warkah, $this->capabilityFlags($request));
    }

    /**
     * Set the bundle's status, and start it if the office has not yet.
     *
     * **Two values only.** `COMPLETE` answers to {@see verify()} because it stamps a
     * pair; `FINALIZED` and `ARCHIVED` answer to nothing. All three are a 422 naming
     * the field — see {@see UpdateWarkahStatusRequest}.
     */
    public function updateStatus(
        UpdateWarkahStatusRequest $request,
        string $deed,
        StartWarkah $start,
        SetWarkahStatus $set,
    ): JsonResponse {
        $record = $this->resolveDeed($deed);

        $this->authorize('manage', [PpatWarkah::class, $record]);

        $warkah = $start->handle($record);

        if (($notes = $request->notes()) !== null) {
            $warkah->notes = $notes;
        }

        return $this->fresh($set->handle($request->user(), $warkah, $request->status()), $request);
    }

    /**
     * Mark the bundle verified — `COMPLETE`, with `verified_at` and `verified_by`.
     *
     * Its own capability, and the only path to that status. See {@see VerifyWarkah}
     * for the three eligibility checks it declines to make and why each would be an
     * invented rule.
     */
    public function verify(
        VerifyWarkahRequest $request,
        string $deed,
        StartWarkah $start,
        VerifyWarkah $verify,
    ): JsonResponse {
        $record = $this->resolveDeed($deed);

        $this->authorize('verify', [PpatWarkah::class, $record]);

        $warkah = $start->handle($record);

        return $this->fresh($verify->handle($request->user(), $warkah, $request->notes()), $request);
    }

    /**
     * The vocabulary the interface renders.
     *
     * **Three reachable statuses and two that are not**, both lists returned so the
     * interface can render `FINALIZED` and `ARCHIVED` on a bundle that somehow carries
     * one while offering neither as a control. The `PpatDeedController::options()`
     * shape, one aggregate over.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PpatWarkah::class);

        return response()->json([
            'data' => [
                'statuses' => array_map(
                    static fn (PpatWarkahStatus $status): string => $status->value,
                    PpatWarkahStatus::reachable(),
                ),

                // Settable through `updateStatus`. `COMPLETE` is absent because it
                // answers to `verify` on its own endpoint (D-091).
                'settable_statuses' => SetWarkahStatus::settableValues(),

                // Storable, reachable by nothing. Named so the interface can render a
                // bundle carrying one without offering a control that sets it.
                'unreachable_statuses' => array_map(
                    static fn (PpatWarkahStatus $status): string => $status->value,
                    PpatWarkahStatus::unreachable(),
                ),
            ],
        ]);
    }

    /**
     * Find a PPAT Deed the caller may reach, or 404.
     *
     * **Resolved under `ppat.warkah.view`, not `ppat.deeds.view`.** The scope predicate
     * runs over `ppat_deeds` either way — that is where a Warkah's reach comes from —
     * but the capability asked for is the Warkah one, so holding deed access without
     * Warkah access reaches nothing here. The two families are separate and this is
     * where that separation is enforced.
     */
    private function resolveDeed(string $deedId): PpatDeed
    {
        $actor = request()->user();

        $record = $this->visibility->scope(
            PpatDeed::query()->whereKey($deedId),
            $actor,
            $this->resolver->resolve($actor, 'ppat.warkah.view'),
        )->first();

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    /**
     * @param  Builder<PpatWarkah>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        // Unrecognized values are ignored rather than erroring: a stale bookmark
        // should show the unfiltered list, not a 422.
        if (PpatWarkahStatus::tryFrom((string) $request->query('status', '')) !== null) {
            $query->where('status', $request->query('status'));
        }

        // *"Which bundles are still short?"* — the question this surface exists for.
        if ($request->boolean('incomplete_only')) {
            $query->where('completeness_percentage', '<', 100);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            // Grouped so the search cannot escape the visibility constraint, and
            // correlated through the deed because a bundle has no title of its own.
            $query->whereHas('deed', function (BuilderContract $deed) use ($search): void {
                $deed->where(function (BuilderContract $inner) use ($search): void {
                    $inner->whereLike('title', "%{$search}%")
                        ->orWhereLike('deed_number', "%{$search}%");
                });
            });
        }
    }

    /**
     * The mutation flags for this actor.
     *
     * Resolved once per request. **No `can_finalize` and no `can_archive`**, because
     * there is nothing behind either — offering a flag for an act with no route would
     * invite an interface to render a control that cannot work.
     *
     * @return array<string, bool>
     */
    private function capabilityFlags(Request $request): array
    {
        $actor = $request->user();
        $flags = [];

        foreach (self::CAPABILITIES as $flag => $permission) {
            $flags[$flag] = $this->visibility->hasUsableScope(
                $this->resolver->resolve($actor, $permission)
            );
        }

        return $flags;
    }

    /**
     * The bundle after an act, always **200**.
     *
     * Laravel's `ResourceResponse` answers 201 when the underlying model
     * `wasRecentlyCreated`, which would make these endpoints return 201 the first time
     * an office touches a deed's bundle and 200 every time after — the same request,
     * two status codes, decided by whether {@see StartWarkah} had to insert a row.
     *
     * That detail is an artefact of there being no `ppat.warkah.create` capability, not
     * something a caller asked for or can predict. The act is *set the status* or
     * *verify*, and both answer 200. Creation has its own 201s where a caller really did
     * create something: a Warkah **item**, and a document attachment.
     */
    private function fresh(PpatWarkah $warkah, Request $request): JsonResponse
    {
        return (new PpatWarkahResource(
            $warkah->load(['deed', 'verifier', 'finalizer'])->loadCount('items'),
            $this->capabilityFlags($request),
        ))->response()->setStatusCode(200);
    }
}
