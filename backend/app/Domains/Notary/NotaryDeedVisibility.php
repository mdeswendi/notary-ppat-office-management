<?php

namespace App\Domains\Notary;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\Matter;
use App\Models\NotaryDeed;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Notarial Deeds an actor's Data Scopes reach (M6.1, D-120).
 *
 * The same shape as `MatterVisibility`, `ProjectVisibility` and `TaskVisibility`,
 * for the same reason: **the record check is the list query.** `permits()` runs the
 * identical constraint against a single key rather than reimplementing the rule, so
 * "which deeds appear in a list" and "which deed may I open" cannot drift apart.
 *
 * ```text
 * OWN       the parent Matter's created_by  = actor id
 * ASSIGNED  the parent Matter's pic_user_id = actor id
 * OFFICE    notary_deeds.office_id          = actor office
 * ALL       every deed in the deployment
 * TEAM      nothing (D-042)
 * ```
 *
 * ## A Deed's reach is its Matter's reach, and that is the narrow reading
 *
 * A deed carries no `pic_user_id`, no `assigned_to` and no `created_by` of its own —
 * see the `notary_deeds` migration for why none was added. So `OWN` and `ASSIGNED`
 * resolve **through the parent Matter**.
 *
 * This looks like the thing `MatterVisibility` forbids and is its opposite, so the
 * distinction is worth stating precisely. D-100 forbids **parent reach becoming
 * child reach**: an actor holding `projects.view` must not thereby see Matters, and
 * a `whereHas('project', …)` branch in `MatterVisibility` would have made
 * `projects.view` a silent superset of `notary.matters.view`.
 *
 * Nothing of that kind happens here. The Matter supplies the **predicate**, never
 * the **grant**:
 *
 * - Holding `notary.matters.view` at any scope reaches **no deed**. Deeds answer to
 *   `notary.deeds.*` and to nothing else.
 * - Holding `notary.deeds.view` at `OWN` reaches deeds on Matters the actor raised —
 *   which is *narrower* than the alternative of giving every deed its own
 *   `created_by`, since a colleague recording a deed on your Matter does not thereby
 *   take it out of your reach or put it into theirs.
 * - **Reaching a deed confers no Matter authority**, the symmetric statement, exactly
 *   as D-100 has it.
 *
 * The union rule is unchanged (D-028): an actor holding `OWN` and `OFFICE` reaches
 * both sets, and there is deliberately no `widest()`, `rank()` or `maxScope()` here.
 *
 * **`deleted_at` is not filtered.** `notary_deeds` has no such column and the model
 * uses no `SoftDeletes` — there is no archive state for this class to have an
 * opinion about.
 *
 * **Neither `status` nor `locked_at` filters anything.** A finalized deed is reached
 * normally; being read-only is not being invisible. Who may *change* it is the
 * Policy's question and the status rule's, not this class's.
 */
class NotaryDeedVisibility
{
    /**
     * The scopes that select an existing Deed. `TEAM` is absent by design.
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
     * Narrow a Deed query to what the actor may reach.
     *
     * @param  Builder<NotaryDeed>  $query
     * @return Builder<NotaryDeed>
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
            // collapsing them would be the ranking D-028 forbids.
            foreach ($scopes as $scope) {
                match ($scope) {
                    DataScope::OFFICE => $outer->orWhere($table.'.office_id', $actor->office_id),

                    // The two that resolve through the parent. An EXISTS subquery
                    // rather than a join, so the outer query returns one row per
                    // deed however many branches match.
                    DataScope::OWN => $outer->orWhereExists(
                        $this->matterPredicate($table, 'created_by', $actor)
                    ),
                    DataScope::ASSIGNED => $outer->orWhereExists(
                        $this->matterPredicate($table, 'pic_user_id', $actor)
                    ),

                    // ALL returned above; TEAM never reaches `usable()`.
                    default => null,
                };
            }
        });
    }

    /**
     * May the actor reach this specific Deed?
     */
    public function permits(User $actor, EffectiveAccess $access, NotaryDeed $deed): bool
    {
        return $this->scope(
            NotaryDeed::query()->whereKey($deed->getKey()),
            $actor,
            $access,
        )->exists();
    }

    /**
     * Does the actor hold this permission at any scope that reaches a Deed?
     *
     * Used for collection-level abilities. A grant carrying only `TEAM` reaches
     * nothing, so it is refused outright rather than serving a reliably empty page.
     */
    public function hasUsableScope(EffectiveAccess $access): bool
    {
        return $this->usable($access) !== [];
    }

    /**
     * "There is a Matter for this deed whose `$column` is the actor."
     *
     * Correlated to the outer row through `matter_id`, so it reads the deed's own
     * parent rather than any Matter at all — the mistake that would turn `OWN` into
     * "the actor has raised at least one Matter somewhere".
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function matterPredicate(string $table, string $column, User $actor)
    {
        return Matter::query()
            ->whereColumn('matters.id', $table.'.matter_id')
            ->where('matters.'.$column, $actor->getKey())
            ->getQuery();
    }

    /**
     * The granted scopes that mean something for a Deed.
     *
     * A denied result yields none, so every fail-closed path in the resolver ends as
     * no reach here too.
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
