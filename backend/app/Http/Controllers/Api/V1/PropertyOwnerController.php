<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Party\ParticipantVisibility;
use App\Domains\Ppat\Actions\AddPropertyOwner;
use App\Domains\Ppat\Actions\UpdatePropertyOwner;
use App\Domains\Ppat\PropertyVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ppat\StorePropertyOwnerRequest;
use App\Http\Requests\Ppat\UpdatePropertyOwnerRequest;
use App\Http\Resources\PropertyOwnerResource;
use App\Models\Matter;
use App\Models\Party;
use App\Models\Property;
use App\Models\PropertyOwner;
use App\Policies\PropertyPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A Property's chain of title (M7.3, D-121).
 *
 * ## Its own surface because it is its own capability
 *
 * `properties.ownership.view` and `properties.ownership.update` are separate canonical
 * codes from `properties.view` and `properties.update` — the split the catalogue drew
 * before anything implemented it, and one a land office would genuinely make: a clerk
 * maintaining addresses is not the person who records a transfer.
 *
 * So reading a parcel does not read who owns it, and correcting an address cannot
 * rewrite the title. That is why these three routes exist rather than fields on
 * {@see PropertyController}.
 *
 * **Nested under the Property and never reachable alone.** There is no
 * `/property-owners/{owner}` address: a link in a chain of title has no independent
 * existence, so an address omitting the Property would name something the domain does
 * not have — the M4.5 convention (D-105), and the same reason M6.3 nested Minuta under
 * its deed.
 *
 * ## Three routes, and the fourth the brief asked for does not exist
 *
 * ```text
 * index    GET    /properties/{property}/owners            properties.ownership.view
 * store    POST   /properties/{property}/owners            properties.ownership.update
 * update   PATCH  /properties/{property}/owners/{owner}    properties.ownership.update
 * ```
 *
 * **There is no `DELETE`.** The brief described it as a *"soft delete ownership"*, and
 * `property_owners` has **no `deleted_at`** — `03_DATABASE_ERD.md` section 16 gives the
 * table nine columns and none of them is one, so M7.1 did not add `SoftDeletes`. A
 * `DELETE` here could only be a hard one, and hard-deleting a link destroys exactly the
 * history the table exists to keep (`CLAUDE.md` sections 30 and 63).
 *
 * Ending an ownership is **closing the link** — `PATCH` with an `effective_until` —
 * which is what a chain of title does when land changes hands, and the closed row stays
 * visible for good. A row entered by mistake is a **correction mechanism**, the same
 * open question that has no answer for deeds either (O-039).
 *
 * **There is no `properties.ownership.create`** either — verified absent. Adding a link
 * is an `update` to the chain, which is the reading the catalogue's two codes support
 * and what the M7 lock section 7.3 records.
 */
class PropertyOwnerController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PropertyVisibility $visibility,
        private readonly MatterVisibility $matters,
        private readonly ParticipantVisibility $participants,
    ) {}

    /**
     * The whole chain, newest first.
     *
     * **Every link, closed ones included.** That is what makes this a chain rather than
     * a current state somebody keeps editing — `CLAUDE.md` section 63, and the reason
     * `source_matter_id` exists at all.
     *
     * Party visibility is computed in **bulk**, one query per subtype branch, the
     * `MatterPartyController` construction. A Party the actor cannot open still appears:
     * the link is a fact about the land, and hiding it would misreport the title to
     * somebody authorized to read it.
     */
    public function index(Request $request, string $property): JsonResponse
    {
        $subject = $this->resolveProperty($property);

        $this->authorize('viewOwnership', $subject);

        $links = PropertyOwner::query()
            ->where('property_id', $subject->getKey())
            // Archived Parties load deliberately: a link the office recorded stays in
            // the chain after the Party is retired.
            ->with(['party' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();

        // The source Matter is loaded only where the caller may reach it: recording a
        // transfer is not authority to read the work behind it (D-100).
        $this->loadReachableSourceMatters($links, $request);

        $parties = $links->pluck('party')->filter()->all();
        $viewable = $this->participants->viewableKeys($parties, $request->user());

        // A Policy ability, never a permission code — the same
        // PropertyPolicy -> EffectiveAccessResolver -> Data Scope chain the mutation
        // endpoints authorize through, so the flag cannot disagree with what they
        // would accept. Presentation only; each of them authorizes again.
        //
        // Evaluated once for the whole list: ownership authority is a property of the
        // parent Property, which does not vary between links. An archived Property is
        // read-only, so the flag folds that in the way `PropertyController` does.
        $canUpdate = $request->user()->can('updateOwnership', $subject)
            && $subject->deleted_at === null;

        return response()->json([
            'data' => $links->map(fn (PropertyOwner $link): array => (
                new PropertyOwnerResource(
                    $link,
                    isset($viewable[$link->party_id]),
                    ['can_update' => $canUpdate],
                )
            )->toArray($request))->all(),

            'meta' => [
                'total' => $links->count(),
                'can_update' => $canUpdate,

                // The arithmetic sum of the current shares, for display. **Not
                // validated against 100**: whether shares must total 100 is a rule
                // about Indonesian co-ownership that no canonical document states
                // (`CLAUDE.md` section 62, M7 lock section 7.2).
                'current_ownership_total' => (float) $links
                    ->where('is_current', true)
                    ->sum(fn (PropertyOwner $link): float => (float) ($link->ownership_percentage ?? 0)),
            ],
        ]);
    }

    /**
     * Add a link to the chain.
     *
     * **The Party is resolved through canonical Party visibility**, per subtype, so
     * `properties.ownership.update` never becomes a way to discover which Parties
     * exist — the double authorization `MatterPartyController::options()` applies, and
     * for the same reason. An unreachable Party gets the same field error a nonexistent
     * one does.
     *
     * `source_matter_id` is resolved the same way, through `ppat.matters.view`.
     *
     * **`supersedes_current` decides which act this is** — a transfer that closes the
     * previous holders, or a co-owner added beside them. See {@see AddPropertyOwner};
     * the M7 lock is explicit that several links may be current at once, so the
     * brief's *"hanya satu owner yang bisa is_current = true"* is not implemented.
     */
    public function store(
        StorePropertyOwnerRequest $request,
        string $property,
        AddPropertyOwner $add,
    ): JsonResponse {
        $subject = $this->resolveProperty($property);

        $this->authorize('updateOwnership', $subject);

        $party = $this->resolveParty($request->partyId(), $subject, $request);
        $sourceMatter = $this->resolveSourceMatter($request->sourceMatterId(), $subject, $request);

        $link = $add->handle(
            $request->user(),
            $subject,
            $party,
            $request->ownerAttributes(),
            $request->supersedesCurrent(),
            $sourceMatter,
        );

        return (new PropertyOwnerResource(
            $link->load(['party' => fn ($query) => $query->withTrashed()]),
            true,
            ['can_update' => true],
        ))->response()->setStatusCode(201);
    }

    /**
     * Correct a link, or close it.
     *
     * Closing is `effective_until` plus a cleared `is_current`, written together so the
     * denormalized flag and the date cannot disagree. The party and the percentage of a
     * closed link are never rewritten — `PropertyOwner` refuses the first outright, and
     * §63 is why.
     */
    public function update(
        UpdatePropertyOwnerRequest $request,
        string $property,
        string $owner,
        UpdatePropertyOwner $update,
    ): PropertyOwnerResource {
        $subject = $this->resolveProperty($property);

        $this->authorize('updateOwnership', $subject);

        $link = $this->resolveLink($subject, $owner);

        $updated = $update->handle($request->user(), $link, $request->ownerAttributes());

        return new PropertyOwnerResource(
            $updated->load(['party' => fn ($query) => $query->withTrashed()]),
            true,
            ['can_update' => true],
        );
    }

    /**
     * Find a Property the caller may reach, or 404.
     *
     * Resolved through `properties.view` rather than the ownership capability: reaching
     * the parcel is the first question and holding the ownership code is the second,
     * and answering 404 on the first keeps an unreachable Property indistinguishable
     * from a nonexistent one (D-098).
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
     * A link belonging to this Property, or 404.
     *
     * Scoped to the parent rather than looked up by id alone, so no address reaches a
     * link without naming the chain it belongs to.
     */
    private function resolveLink(Property $property, string $ownerId): PropertyOwner
    {
        $link = PropertyOwner::query()
            ->where('property_id', $property->getKey())
            ->whereKey($ownerId)
            ->first();

        if ($link === null) {
            abort(404);
        }

        return $link;
    }

    /**
     * A Party the actor may reach, per subtype, or one indistinguishable field error.
     */
    private function resolveParty(string $partyId, Property $property, Request $request): Party
    {
        $party = Party::query()
            ->whereKey($partyId)
            ->where('office_id', $property->office_id)
            ->first();

        $reachable = $party !== null
            && $this->participants->viewableKeys([$party], $request->user()) !== [];

        if (! $reachable) {
            throw ValidationException::withMessages([
                'party_id' => __('validation.exists', ['attribute' => 'party id']),
            ]);
        }

        return $party;
    }

    /**
     * The transfer that produced a link, if the caller named one they may reach.
     */
    private function resolveSourceMatter(?string $matterId, Property $property, Request $request): ?Matter
    {
        if ($matterId === null) {
            return null;
        }

        $actor = $request->user();

        $matter = $this->matters->scope(
            Matter::query()
                ->whereKey($matterId)
                ->where('office_id', $property->office_id)
                ->where('domain', MatterDomain::PPAT->value),
            $actor,
            $this->resolver->resolve($actor, 'ppat.matters.view'),
        )->first();

        if ($matter === null) {
            throw ValidationException::withMessages([
                'source_matter_id' => __('validation.exists', ['attribute' => 'source matter id']),
            ]);
        }

        return $matter;
    }

    /**
     * Load `sourceMatter` only on links whose Matter this caller may reach.
     *
     * One scoped query for the whole list rather than one per link. A link whose source
     * Matter is out of reach still appears, with a null `source_matter` — the link is a
     * fact about the land, and the work behind it is a separate question (D-100).
     *
     * @param  Collection<int, PropertyOwner>  $links
     */
    private function loadReachableSourceMatters($links, Request $request): void
    {
        $ids = $links->pluck('source_matter_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return;
        }

        $actor = $request->user();

        $reachable = $this->matters->scope(
            Matter::query()->whereIn('id', $ids),
            $actor,
            $this->resolver->resolve($actor, 'ppat.matters.view'),
        )->get()->keyBy('id');

        foreach ($links as $link) {
            $link->setRelation('sourceMatter', $reachable->get($link->source_matter_id));
        }
    }
}
