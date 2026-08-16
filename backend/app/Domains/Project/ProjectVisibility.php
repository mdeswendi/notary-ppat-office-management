<?php

namespace App\Domains\Project;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Projects an actor's Data Scopes reach (D-088).
 *
 * The same shape as `PartyVisibility` and `UserVisibility`, for the same reason:
 * **the record check is the list query.** `permits()` runs the identical
 * constraint against a single key rather than reimplementing the rule, so "which
 * Projects appear in a list" and "which Project may I open" cannot drift apart —
 * the failure mode where a record is hidden from a listing yet still fetchable
 * by id.
 *
 * Where it differs from Party is the part that matters. Party has two usable
 * scopes; Project has **four**, and they must **union**:
 *
 *   OWN       projects.created_by  = actor id
 *   ASSIGNED  projects.pic_user_id = actor id
 *   OFFICE    projects.office_id   = actor office
 *   ALL       every Project in the deployment
 *   TEAM      nothing
 *
 * **These are predicates, not a ladder** (D-028). An actor holding `OWN` and
 * `OFFICE` reaches the union of both sets — their own Projects *and* their
 * Office's — not "the wider of the two", because there is no such thing. There
 * is deliberately no `widest()`, `rank()`, or `maxScope()` in this class, and a
 * test asserts their absence.
 *
 * **`OWN` is `created_by` here, and that is not a contradiction of M2.** D-080
 * withheld `OWN` from Party on Party-specific reasoning: a Party is a shared
 * directory record, and the colleague who typed it in has no claim on the person
 * it describes. A Project is not a shared reference record — it is a unit of work
 * somebody opened. The reasoning did not transfer, so the answer did not either.
 *
 * **`TEAM` fails closed**, as it does everywhere: no Team entity exists (D-042),
 * so a grant carrying only `TEAM` reaches no Project at all. So does a grant with
 * no usable scope, and so does a denied result — no usable predicate is *not*
 * "no restriction", it is no access.
 *
 * **Future Matter or stage assignment must never be added to this class.** When
 * M4 introduces `matters.pic_user_id` and stage assignees, extending `ASSIGNED`
 * to cover them would be a new grant wearing an existing scope's name, silently
 * widening every role already configured with Project `ASSIGNED` without anybody
 * editing a role. If Matter workers need Project visibility, that is its own
 * decision and its own predicate.
 */
class ProjectVisibility
{
    /**
     * The scopes that select a Project. `TEAM` is absent by design.
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
     * Narrow a Project query to what the actor may reach.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
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
     * May the actor reach this specific Project?
     *
     * `$includeArchived` exists for exactly one caller: the `restore` ability.
     * A soft-deleted Project is invisible to the ordinary predicate, so asking
     * "may this actor restore it" through the default query would answer no for
     * every record — the permission would be unusable by construction (D-093).
     * Nothing else passes true, and the flag widens *which rows are considered*,
     * never which scopes apply.
     */
    public function permits(
        User $actor,
        EffectiveAccess $access,
        Project $project,
        bool $includeArchived = false,
    ): bool {
        $query = Project::query()->whereKey($project->getKey());

        if ($includeArchived) {
            $query->withTrashed();
        }

        return $this->scope($query, $actor, $access)->exists();
    }

    /**
     * May the actor create a Project in this Office?
     *
     * Creation has no target record, so the destination Office is the subject —
     * and `OWN` and `ASSIGNED` have nothing to match against, because the record
     * whose creator or PIC they describe does not exist yet. Reading them as
     * "may create anything they will own" would let an office-scoped actor create
     * in any Office at all, which inverts the boundary.
     *
     * So the rule is the same one Party uses: **only `ALL` may create elsewhere**;
     * every other usable scope confines creation to the actor's own Office. This
     * is a backend decision — office selection is never a frontend-only rule.
     */
    public function permitsCreationIn(User $actor, EffectiveAccess $access, string $officeId): bool
    {
        $scopes = $this->usable($access);

        if ($scopes === []) {
            return false;
        }

        if (in_array(DataScope::ALL, $scopes, true)) {
            return true;
        }

        return $officeId === $actor->office_id;
    }

    /**
     * Does this grant reach beyond the actor's own Office?
     *
     * Exact membership of `ALL`, never a comparison (D-028).
     */
    public function reachesAllOffices(EffectiveAccess $access): bool
    {
        return in_array(DataScope::ALL, $this->usable($access), true);
    }

    /**
     * Does the actor hold this permission at any scope that reaches a Project?
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
     * The granted scopes that mean something for a Project.
     *
     * A denied result yields none, so every fail-closed path in the resolver
     * ends as no reach here too.
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
