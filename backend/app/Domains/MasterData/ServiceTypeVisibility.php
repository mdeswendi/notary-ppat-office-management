<?php

namespace App\Domains\MasterData;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Service Types an actor's Data Scopes reach (M4.1).
 *
 * The same shape as `ProjectVisibility`, `PartyVisibility` and `UserVisibility`,
 * for the same reason: **the record check is the list query.** `permits()` runs
 * the identical constraint against a single key rather than reimplementing the
 * rule, so "which Service Types appear in a catalogue" and "which one may I open"
 * cannot drift apart — the failure mode where a record is hidden from a listing
 * yet still fetchable by id.
 *
 * **Two usable scopes, and the other three fail closed:**
 *
 *   OFFICE   service_types.office_id = actor office
 *   ALL      every Service Type in the deployment
 *   OWN      nothing
 *   ASSIGNED nothing
 *   TEAM     nothing
 *
 * This is the Party answer (D-080) rather than the Project one (D-088), and the
 * reasoning transfers exactly. `OWN` would have to mean `created_by`, and this
 * table deliberately has no such column: a Service Type is a **shared reference
 * record**, and the colleague who typed it in has no claim on the service the
 * office offers. `ASSIGNED` has no assignment entity to match — nobody is the PIC
 * of a catalogue entry. `TEAM` has no Team entity anywhere (D-042).
 *
 * Withholding them here is not cosmetic. `PermissionScopeRules` offers exactly
 * the two scopes this class can honour, so an administrator cannot grant
 * `master.services.view` at `OWN`, see it saved, and receive a silently powerless
 * grant — the dead-control failure D-080 named.
 *
 * **These are predicates, not a ladder** (D-028). An actor holding `OFFICE` and
 * `ALL` reaches the union, not "the wider of the two", because there is no such
 * thing. There is deliberately no `widest()`, `rank()`, or `maxScope()` here, and
 * a test asserts their absence.
 *
 * **Creation is a narrower question and lives in the Policy**, not here: a
 * Service Type is always created into the actor's own Office, so `ALL` grants
 * reach over existing records and never the right to choose a destination.
 */
class ServiceTypeVisibility
{
    /**
     * The scopes that select an existing Service Type.
     *
     * `OWN`, `ASSIGNED` and `TEAM` are absent by design — see the class note.
     *
     * @return array<int, DataScope>
     */
    private const APPLICABLE = [
        DataScope::ALL,
        DataScope::OFFICE,
    ];

    /**
     * Narrow a Service Type query to what the actor may reach.
     *
     * @param  Builder<ServiceType>  $query
     * @return Builder<ServiceType>
     */
    public function scope(Builder $query, User $actor, EffectiveAccess $access): Builder
    {
        $scopes = $this->usable($access);

        // No usable predicate is not "no restriction" — it is no access.
        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }

        // ALL imposes no record restriction, so it short-circuits the rest.
        // Evaluating the others alongside it could only widen nothing.
        if (in_array(DataScope::ALL, $scopes, true)) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (BuilderContract $outer) use ($actor, $scopes, $table): void {
            // One OR branch per granted scope. The union is the point: collapsing
            // them to one would be the ranking D-028 forbids.
            foreach ($scopes as $scope) {
                match ($scope) {
                    DataScope::OFFICE => $outer->orWhere($table.'.office_id', $actor->office_id),
                    // ALL returned above; OWN, ASSIGNED and TEAM never reach
                    // `usable()`.
                    default => null,
                };
            }
        });
    }

    /**
     * May the actor reach this specific Service Type?
     *
     * Inactive Service Types are reached normally. `is_active` is a catalogue
     * availability flag, not a visibility rule: somebody administering the
     * catalogue must be able to see what they retired, and a record referencing a
     * retired service must stay readable (CLAUDE.md section 63).
     */
    public function permits(User $actor, EffectiveAccess $access, ServiceType $serviceType): bool
    {
        return $this->scope(
            ServiceType::query()->whereKey($serviceType->getKey()),
            $actor,
            $access,
        )->exists();
    }

    /**
     * Does the actor hold this permission at any scope that reaches a Service
     * Type?
     *
     * Used for catalogue-level abilities. A grant carrying only `OWN`,
     * `ASSIGNED`, or `TEAM` reaches nothing, so it is refused outright rather
     * than serving a reliably empty catalogue.
     */
    public function hasUsableScope(EffectiveAccess $access): bool
    {
        return $this->usable($access) !== [];
    }

    /**
     * May the actor create a Service Type in this Office?
     *
     * **Creation is always into the actor's own Office**, so the only honest
     * answer for any other destination is no — including for an actor holding
     * `ALL`. `ALL` is reach over records that already exist; it is not authority
     * to decide which Office a new one belongs to. That is the same line D-098
     * drew for participation: `ALL` grants visibility and administrative reach,
     * never permission to redefine domain ownership.
     *
     * `OFFICE` therefore qualifies because the record lands where the predicate
     * already points. `ALL` qualifies only for the actor's own Office, which is
     * the one place it agrees with `OFFICE` anyway.
     */
    public function permitsCreationIn(User $actor, EffectiveAccess $access, ?string $officeId = null): bool
    {
        if ($this->usable($access) === []) {
            return false;
        }

        // A caller naming no destination is asking the general question: may this
        // actor create a Service Type at all? They can, in their own Office.
        if ($officeId === null) {
            return $actor->office_id !== null;
        }

        return $officeId === $actor->office_id;
    }

    /**
     * The granted scopes that mean something for a Service Type.
     *
     * A denied result yields none, so every fail-closed path in the resolver ends
     * as no reach here too.
     *
     * @return array<int, DataScope>
     */
    private function usable(EffectiveAccess $access): array
    {
        if (! $access->granted) {
            return [];
        }

        return array_values(array_filter(
            $access->scopes,
            fn (DataScope $scope): bool => in_array($scope, self::APPLICABLE, true),
        ));
    }
}
