<?php

namespace App\Domains\Matter;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Matters an actor's Data Scopes reach (M4.2, D-100).
 *
 * The same shape as `ProjectVisibility`, `PartyVisibility` and
 * `ServiceTypeVisibility`, for the same reason: **the record check is the list
 * query.** `permits()` runs the identical constraint against a single key rather
 * than reimplementing the rule, so "which Matters appear in a list" and "which
 * Matter may I open" cannot drift apart — the failure mode where a record is
 * hidden from a listing yet still fetchable by id.
 *
 * ```text
 *   OWN       matters.created_by  = actor id
 *   ASSIGNED  matters.pic_user_id = actor id
 *   OFFICE    matters.office_id   = actor office
 *   ALL       every Matter in the deployment
 *   TEAM      nothing
 * ```
 *
 * **These are predicates, not a ladder** (D-028). An actor holding `OWN` and
 * `OFFICE` reaches the union of both sets — their own Matters *and* their
 * Office's — not "the wider of the two", because there is no such thing. There is
 * deliberately no `widest()`, `rank()`, or `maxScope()` in this class, and a test
 * asserts their absence.
 *
 * **`TEAM` fails closed**, as everywhere: no Team entity exists (D-042). So does
 * a grant with no usable scope, and so does a denied result — no usable predicate
 * is *not* "no restriction", it is no access.
 *
 * **Two things must never be added to this class**, and both are tempting:
 *
 * **Parent Project reach is not Matter reach** (D-100). An actor who may view,
 * update, or archive a Project gains by that fact alone no right to see any
 * Matter beneath it. Adding a `whereHas('project', …)` branch here would make
 * Project reach a silent superset of Matter reach, so an administrator granting
 * `projects.view` would have granted Notary and PPAT work visibility without ever
 * naming those capabilities. The one place the parent is consulted is *creation*,
 * and that lives in the Policy.
 *
 * **Stage assignment is not Matter assignment** (D-100). When M4.7 adds
 * `matter_stage_instances.assigned_user_id`, extending `ASSIGNED` to cover it
 * would be a new grant wearing an existing scope's name, silently widening every
 * role already configured with Matter `ASSIGNED` without anybody editing a role.
 * If stage workers need Matter visibility, that is its own decision and its own
 * predicate.
 *
 * **`deleted_at` is not filtered here.** The column is reserved schema capability
 * with no lifecycle (D-102) and the model uses no `SoftDeletes`, so there is no
 * archive state for this class to have an opinion about. When an archive
 * milestone exists it decides that question deliberately.
 */
class MatterVisibility
{
    /**
     * The scopes that select an existing Matter. `TEAM` is absent by design.
     *
     * @return array<int, DataScope>
     */
    private const APPLICABLE = [
        DataScope::ALL,
        DataScope::OFFICE,
        DataScope::ASSIGNED,
        DataScope::OWN,
    ];

    /**
     * The scopes that can describe a Matter **about to be created**.
     *
     * A narrower set than {@see APPLICABLE}, and the difference is `ASSIGNED`.
     * Every other predicate can be evaluated against the record about to exist:
     * `OWN` will match because the actor becomes `created_by`, `OFFICE` because
     * the Matter inherits the Project's Office, and `ALL` because it matches
     * everything.
     *
     * **`ASSIGNED` cannot.** A new Matter starts with no PIC (D-107), so
     * `pic_user_id == actor.id` is false for the record the actor is asking to
     * create, and stays false until somebody with the assign capability says
     * otherwise. This is not an exception to the union rule (D-028): the
     * predicate simply does not match, and an actor holding `ASSIGNED` **and**
     * `OFFICE` creates normally because `OFFICE` matches.
     *
     * @return array<int, DataScope>
     */
    private const APPLICABLE_TO_CREATION = [
        DataScope::ALL,
        DataScope::OFFICE,
        DataScope::OWN,
    ];

    /**
     * Narrow a Matter query to what the actor may reach.
     *
     * @param  Builder<Matter>  $query
     * @return Builder<Matter>
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

        return $query->where(function (BuilderContract $outer) use ($actor, $scopes, $table): void {
            // One OR branch per granted scope. The union is the whole point:
            // dropping a branch would discard access an administrator granted,
            // and collapsing them to one would be the ranking D-028 forbids.
            foreach ($scopes as $scope) {
                match ($scope) {
                    DataScope::OWN => $outer->orWhere($table.'.created_by', $actor->getKey()),
                    DataScope::ASSIGNED => $outer->orWhere($table.'.pic_user_id', $actor->getKey()),
                    DataScope::OFFICE => $outer->orWhere($table.'.office_id', $actor->office_id),
                    // ALL returned above; TEAM never reaches `usable()`.
                    default => null,
                };
            }
        });
    }

    /**
     * May the actor reach this specific Matter?
     */
    public function permits(User $actor, EffectiveAccess $access, Matter $matter): bool
    {
        return $this->scope(
            Matter::query()->whereKey($matter->getKey()),
            $actor,
            $access,
        )->exists();
    }

    /**
     * Does the actor hold this permission at any scope that reaches a Matter?
     *
     * Used for list-level abilities. A grant carrying only `TEAM` reaches
     * nothing, so it is refused outright rather than serving a reliably empty
     * page.
     */
    public function hasUsableScope(EffectiveAccess $access): bool
    {
        return $this->usable($access) !== [];
    }

    /**
     * Does this grant carry a scope that could describe a Matter about to exist?
     *
     * Creation additionally requires an eligible parent Project and the actor's
     * own Office — both decided in the Matter Policy, because they are questions
     * about the Project rather than about a Matter record.
     */
    public function permitsCreation(EffectiveAccess $access): bool
    {
        return $this->usableForCreation($access) !== [];
    }

    /**
     * The granted scopes that mean something for a Matter.
     *
     * A denied result yields none, so every fail-closed path in the resolver ends
     * as no reach here too.
     *
     * @return array<int, DataScope>
     */
    private function usable(EffectiveAccess $access): array
    {
        return $this->filterScopes($access, self::APPLICABLE);
    }

    /**
     * @return array<int, DataScope>
     */
    private function usableForCreation(EffectiveAccess $access): array
    {
        return $this->filterScopes($access, self::APPLICABLE_TO_CREATION);
    }

    /**
     * @param  array<int, DataScope>  $applicable
     * @return array<int, DataScope>
     */
    private function filterScopes(EffectiveAccess $access, array $applicable): array
    {
        if (! $access->granted) {
            return [];
        }

        return array_values(array_filter(
            $access->scopes,
            fn (DataScope $scope): bool => in_array($scope, $applicable, true),
        ));
    }
}
