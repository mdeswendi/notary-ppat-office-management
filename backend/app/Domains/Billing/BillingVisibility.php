<?php

namespace App\Domains\Billing;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Which billing records an actor may reach (M8.2, D-124).
 *
 * One class for all five surfaces, because they share a predicate exactly: every
 * billing table carries `office_id` and nothing else selects rows. Five identical
 * visibility classes would be five places for one rule to drift.
 *
 * ## Two scopes, not four
 *
 * ```text
 * OFFICE    office_id = actor office
 * ALL       cross-office reach
 * OWN       withheld
 * ASSIGNED  withheld
 * TEAM      no grant — no Team entity exists (D-042)
 * ```
 *
 * **`OWN` and `ASSIGNED` are withheld deliberately**, the way D-080 withheld them
 * for Party and D-121 for Property. An invoice is the **Office's** claim on a
 * client, not the personal work of whoever typed it: a scope meaning "invoices I
 * raised" would let somebody be granted billing reach that silently excluded the
 * money their colleagues are collecting, which is not a distinction any office
 * wants to draw about its own accounts receivable.
 *
 * The rows do carry `created_by`, and it is attribution — who to ask about this
 * record — rather than a reach predicate (D-050).
 *
 * **Data Scopes are predicates, never a ladder** (D-028). An unknown or missing
 * scope fails closed (D-039), and no usable predicate means no access rather than
 * no restriction.
 *
 * ## What is not a predicate here
 *
 * **`status` filters nothing.** A cancelled invoice is reached normally, and so is
 * a draft: they are lifecycle facts a caller filters on when they choose to, not
 * conditions on who may look. `deleted_at` is handled by each model's global
 * scope.
 *
 * **Nor does `billing.amount.view`.** Masking money is a serialization concern
 * (D-125) — it decides what a reachable record *discloses*, never which records
 * are reachable. Folding it in here would hide whole invoices from somebody
 * entitled to know they exist.
 */
class BillingVisibility
{
    /**
     * The scopes that can select a billing record at all.
     */
    private const APPLICABLE = [
        DataScope::ALL,
        DataScope::OFFICE,
    ];

    /**
     * Narrow a billing query to what the actor may reach.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, User $actor, EffectiveAccess $access): Builder
    {
        $scopes = $this->usable($access);

        // No usable predicate is not "no restriction" — it is no access.
        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }

        // ALL imposes no record restriction, so it short-circuits the rest.
        if (in_array(DataScope::ALL, $scopes, true)) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $query->where($table.'.office_id', $actor->office_id);
    }

    /**
     * May the actor reach this specific record?
     */
    public function permits(User $actor, EffectiveAccess $access, Model $record): bool
    {
        if (! $access->granted) {
            return false;
        }

        $scopes = $this->usable($access);

        if ($scopes === []) {
            return false;
        }

        if (in_array(DataScope::ALL, $scopes, true)) {
            return true;
        }

        return $record->getAttribute('office_id') === $actor->office_id;
    }

    /**
     * Does the actor hold this permission at any scope that reaches a record?
     *
     * Used for collection-level abilities. A grant carrying only `TEAM`, `OWN` or
     * `ASSIGNED` reaches nothing here, so it is refused outright rather than
     * serving a reliably empty list.
     */
    public function hasUsableScope(EffectiveAccess $access): bool
    {
        return $this->usable($access) !== [];
    }

    /**
     * May the actor raise a billing record in this Office?
     *
     * **Always their own Office**, so the only honest answer for any other
     * destination is no — including for an actor holding `ALL`. `ALL` is reach
     * over records that already exist; it is not authority to decide which Office
     * a new one belongs to. The line D-097, D-098, D-107 and D-119 all drew.
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
     * The granted scopes that mean something for billing, in canonical order.
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
