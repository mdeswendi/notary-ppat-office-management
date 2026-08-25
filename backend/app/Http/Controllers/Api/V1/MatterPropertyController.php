<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Ppat\Actions\AttachMatterProperty;
use App\Domains\Ppat\Actions\DetachMatterProperty;
use App\Domains\Ppat\PropertyVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ppat\AttachMatterPropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Matter;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which land a Matter concerns (M7.3, D-121).
 *
 * ## Two capabilities, deliberately, because there is no third
 *
 * **The catalogue has no `*.matters.properties.*` code and no `properties.matters.*`
 * code** — verified against the registry, the same check that found `ppat.deeds.delete`
 * and `properties.delete` absent. So each act is judged by the two capabilities that
 * already exist:
 *
 * ```text
 * index     ppat.matters.view    (reach the Matter)  + properties.view
 * store     ppat.matters.update  (compose the Matter) + properties.view (reach the target)
 * destroy   ppat.matters.update
 * ```
 *
 * **Writing is the Matter's capability** because the junction row is Matter
 * composition: it says which parcel this piece of work is about, the way participation
 * says which people it is about. `ppat.matters.update` already lets somebody change
 * what a Matter is.
 *
 * **Reading is the Property's**, which is what `Matter::properties()` records in its
 * own docblock: *"Reading it answers to `properties.view`, not to the Matter
 * capability."* Both must hold — the Matter to name it in the address, the Property
 * capability to see what comes back.
 *
 * **The target is resolved through canonical `properties.view` visibility**, so
 * `ppat.matters.update` never becomes a way to discover which Properties exist. An
 * unreachable Property gets the same field error a nonexistent one does — the M7.2
 * `store` construction and D-118's two-question rule.
 *
 * ## PPAT only, and the route says so
 *
 * The prefix is `/ppat/matters/{matter}/properties`, so the namespace this authorizes
 * through is fixed by the address rather than by the record (D-101). There is no
 * `/notary/…` counterpart: `CLAUDE.md` section 16 lists Property among the
 * PPAT-specific concepts, and a Notary Matter naming land would be a claim about
 * Notary practice nobody here may make. The junction is domain-agnostic in the schema,
 * which is the ERD's business; only PPAT reaches it.
 *
 * `DELETE` genuinely deletes — the junction row only, never the Matter and never the
 * Property. `matter_properties` has no `deleted_at`, so the interface says so rather
 * than implying an undo the product does not have.
 */
class MatterPropertyController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly MatterVisibility $matters,
        private readonly PropertyVisibility $properties,
    ) {}

    /**
     * The parcels this Matter names.
     *
     * **Rows the caller may not reach under `properties.view` are excluded**, not
     * blanked: unlike participation — where a Party the actor cannot open still appears
     * because the row is Matter data — a Property is an independent record with its own
     * Data Scope, and listing one the caller cannot open would leak that it exists.
     * Both scopes are `OFFICE`/`ALL`, so in practice this only bites across Offices,
     * where the composite keys already make the pair unrepresentable.
     */
    public function index(Request $request, string $matter): JsonResponse
    {
        $subject = $this->resolveMatter($matter);

        $this->authorize('viewAny', Property::class);

        $actor = $request->user();

        // Queried on `Property` rather than through the relation, so
        // `PropertyVisibility` applies its predicate to the model's own table. Pulling
        // `$subject->properties()->getQuery()` would hand back a builder the relation
        // has not finished configuring, and the pivot would arrive unhydrated.
        $properties = $this->properties->scope(
            Property::query()
                ->whereHas('matters', fn (Builder $matter) => $matter->where('matters.id', $subject->getKey()))
                ->with(['office']),
            $actor,
            $this->resolver->resolve($actor, 'properties.view'),
        )
            ->orderBy('property_number')
            ->orderBy('id')
            ->get();

        // The role each parcel plays in *this* transaction, read from the junction in
        // one query rather than through a pivot on every row.
        $roles = DB::table('matter_properties')
            ->where('matter_id', $subject->getKey())
            ->pluck('role_code', 'property_id');

        // A Policy ability, never a permission code. Evaluated once for the whole
        // list: composing a Matter is a property of the Matter, not of each row.
        $canManage = $actor->can('update', [$subject, MatterDomain::PPAT]);

        return response()->json([
            'data' => $properties->map(fn (Property $property): array => array_merge(
                (new PropertyResource($property))->toArray($request),
                [
                    // Opaque and never validated against a catalogue: the ERD calls
                    // its three values "Example role codes".
                    'role_code' => $roles[$property->getKey()] ?? null,
                ],
            ))->all(),

            'meta' => [
                'total' => $properties->count(),
                'can_manage' => $canManage,
            ],
        ]);
    }

    public function store(
        AttachMatterPropertyRequest $request,
        string $matter,
        AttachMatterProperty $attach,
    ): JsonResponse {
        $subject = $this->resolveMatter($matter);

        $this->authorize('update', [$subject, MatterDomain::PPAT]);

        $property = $this->resolveProperty($request->propertyId(), $subject, $request);

        $attach->handle($subject, $property, $request->roleCode());

        return response()->json([
            'data' => array_merge(
                (new PropertyResource($property->load('office')))->toArray($request),
                ['role_code' => $request->roleCode()],
            ),
        ], 201);
    }

    public function destroy(
        Request $request,
        string $matter,
        string $property,
        DetachMatterProperty $detach,
    ): JsonResponse {
        $subject = $this->resolveMatter($matter);

        $this->authorize('update', [$subject, MatterDomain::PPAT]);

        $record = Property::query()
            ->whereKey($property)
            ->whereHas('matters', fn (Builder $matter) => $matter->where('matters.id', $subject->getKey()))
            ->first();

        if ($record === null) {
            abort(404);
        }

        $detach->handle($subject, $record);

        return response()->json(status: 204);
    }

    /**
     * A PPAT Matter the caller may reach, or 404.
     */
    private function resolveMatter(string $matterId): Matter
    {
        $actor = request()->user();

        $matter = $this->matters->scope(
            Matter::query()
                ->whereKey($matterId)
                ->where('domain', MatterDomain::PPAT->value),
            $actor,
            $this->resolver->resolve($actor, 'ppat.matters.view'),
        )->first();

        if ($matter === null) {
            abort(404);
        }

        return $matter;
    }

    /**
     * A Property the actor may reach in the Matter's Office, or one indistinguishable
     * field error.
     *
     * **Archived parcels are refused as attachment targets.** A retired record should
     * not be picked for new work — the rule `MatterPartyController` applies to archived
     * Parties, for the same reason.
     */
    private function resolveProperty(string $propertyId, Matter $matter, Request $request): Property
    {
        $actor = $request->user();

        $property = $this->properties->scope(
            Property::query()
                ->whereKey($propertyId)
                ->where('office_id', $matter->office_id),
            $actor,
            $this->resolver->resolve($actor, 'properties.view'),
        )->first();

        if ($property === null) {
            throw ValidationException::withMessages([
                'property_id' => __('validation.exists', ['attribute' => 'property id']),
            ]);
        }

        return $property;
    }
}
