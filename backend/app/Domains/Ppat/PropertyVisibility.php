<?php

namespace App\Domains\Ppat;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Properties an actor's Data Scopes reach (M7.1, D-121).
 *
 * The same shape as every visibility class since M2: **the record check is the list
 * query**, so "which properties appear in a list" and "which property may I open"
 * cannot drift apart.
 *
 * ```text
 * OFFICE  properties.office_id = actor office
 * ALL     every Property in the deployment
 * OWN       withheld
 * ASSIGNED  withheld
 * TEAM      no grant (D-042)
 * ```
 *
 * ## Two scopes, not four, and the reason is what a Property *is*
 *
 * This follows the **Party answer** (D-080) and the Service Type answer (D-106),
 * not the Project one (D-088). A Property is **office-owned reference data**: it
 * exists before any Matter names it, it outlives every one of them, and several
 * unrelated transactions may touch the same parcel over years.
 *
 * - **`OWN` is withheld** because it would have to mean `created_by`, and the
 *   colleague who typed in a land parcel has no claim on it — precisely the argument
 *   D-080 made for Party and D-106 for Service Type. The table carries `created_by`
 *   for attribution (D-050), which is a different thing from ownership of the record.
 * - **`ASSIGNED` is withheld** because there is no Property assignment entity.
 *   Nobody is the PIC of a land parcel.
 *
 * Withholding them here matters because the Permission Matrix offers exactly the list
 * `PermissionScopeRules` returns. Leaving `properties.*` in the permissive default
 * would let an administrator grant `properties.view` at `OWN`, see it saved, and get
 * a silently powerless grant — the dead control D-080 named.
 *
 * ## What must never be added
 *
 * **Matter reach is not Property reach.** A `whereHas('matters', …)` branch here
 * would make anyone who can see a PPAT Matter able to see every parcel it touches,
 * turning `ppat.matters.view` into a silent superset of `properties.view`. That is
 * D-100 in the shape it takes for reference data, and it is tempting precisely
 * because the junction makes it easy.
 *
 * **Ownership is not reach either.** Being recorded in `property_owners` says
 * something about a Party, not about a User, and the two are different populations —
 * a Party is not an account.
 *
 * **`deleted_at` is not filtered here.** The model uses `SoftDeletes`, so its global
 * scope has already removed retired rows before this class sees the query.
 */
class PropertyVisibility
{
    /**
     * The scopes that select a Property. `OWN`, `ASSIGNED` and `TEAM` are absent by
     * design — see the class docblock.
     *
     * @return array<int, DataScope>
     */
    private const APPLICABLE = [
        DataScope::ALL,
        DataScope::OFFICE,
    ];

    /**
     * Narrow a Property query to what the actor may reach.
     *
     * @param  Builder<Property>  $query
     * @return Builder<Property>
     */
    public function scope(Builder $query, User $actor, EffectiveAccess $access): Builder
    {
        $scopes = $this->usable($access);

        // No usable predicate is not "no restriction" — it is no access.
        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }

        // ALL imposes no record restriction.
        if (in_array(DataScope::ALL, $scopes, true)) {
            return $query;
        }

        return $query->where($query->getModel()->getTable().'.office_id', $actor->office_id);
    }

    /**
     * May the actor reach this specific Property?
     */
    public function permits(User $actor, EffectiveAccess $access, Property $property): bool
    {
        return $this->scope(
            Property::query()->whereKey($property->getKey()),
            $actor,
            $access,
        )->exists();
    }

    /**
     * Does the actor hold this permission at any scope that reaches a Property?
     *
     * A grant carrying only `OWN`, `ASSIGNED` or `TEAM` reaches nothing here, so it is
     * refused outright rather than serving a reliably empty page.
     */
    public function hasUsableScope(EffectiveAccess $access): bool
    {
        return $this->usable($access) !== [];
    }

    /**
     * May the actor create a Property, and in which Office?
     *
     * **Always their own Office**, so the only honest answer for any other
     * destination is no — including for an actor holding `ALL`. `ALL` is reach over
     * records that already exist; it is never authority to decide which Office a new
     * one belongs to. The line D-097, D-098, D-107 and D-119 all drew.
     */
    public function permitsCreationIn(User $actor, EffectiveAccess $access, ?string $officeId = null): bool
    {
        if ($this->usable($access) === []) {
            return false;
        }

        $officeId ??= $actor->office_id;

        return $officeId === $actor->office_id;
    }

    /**
     * The granted scopes that mean something for a Property, in canonical order.
     *
     * @return array<int, DataScope>
     */
    private function usable(EffectiveAccess $access): array
    {
        if (! $access->granted) {
            return [];
        }

        return array_values(array_filter(
            self::APPLICABLE,
            static fn (DataScope $scope): bool => $access->hasScope($scope),
        ));
    }
}
