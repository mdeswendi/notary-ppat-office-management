<?php

namespace App\Domains\Party;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\Party;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Parties an actor's Data Scopes reach (D-080).
 *
 * Deliberately the same shape as `UserVisibility`, because the failure it
 * prevents is the same: **the record check is the list query**. `permits()` runs
 * the identical constraint against a single key rather than reimplementing the
 * rule, so "which Parties appear in the list" and "which Party may I open"
 * cannot drift apart — the failure mode where a record is hidden from a listing
 * yet still fetchable by id.
 *
 * Only two of the five scopes mean anything here:
 *
 *   OFFICE    parties.office_id equals the actor's office_id
 *   ALL       every Party in the deployment
 *   OWN       nothing
 *   ASSIGNED  nothing
 *   TEAM      nothing
 *
 * The three that mean nothing are the ones worth explaining, because each has an
 * obvious-looking wrong answer:
 *
 * `OWN` must not become `created_by`. A Party is a shared office directory
 * record; the colleague who typed it in has no special claim on the person or
 * organization it describes, and reading "own" as "records I entered" would hand
 * out access nobody granted.
 *
 * `ASSIGNED` has nothing to match. No Party assignment entity exists, and
 * inventing one so the scope has work to do would be building a feature to
 * justify a word.
 *
 * `TEAM` must never collapse to `OFFICE`. No Team entity exists (D-042), and
 * quietly aliasing them would grant office-wide reach to a scope an
 * administrator chose precisely because it was narrower.
 *
 * All three **fail closed**: a grant carrying only those reaches no Party at all.
 * Union, never ranking (D-028) — `{OFFICE, ALL}` matches everything because
 * `ALL` is an independent predicate that happens to subsume the other, not
 * because it outranks it.
 */
class PartyVisibility
{
    /**
     * The only scopes that select a Party.
     *
     * @return array<int, DataScope>
     */
    private const APPLICABLE = [DataScope::ALL, DataScope::OFFICE];

    /**
     * Narrow a Party query to what the actor may reach.
     *
     * @param  Builder<Party>  $query
     * @return Builder<Party>
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

        return $query->where(function (BuilderContract $inner) use ($actor, $table): void {
            $inner->where($table.'.office_id', $actor->office_id);
        });
    }

    /**
     * May the actor reach this specific Party?
     */
    public function permits(User $actor, EffectiveAccess $access, Party $party): bool
    {
        return $this->scope(Party::query()->whereKey($party->getKey()), $actor, $access)->exists();
    }

    /**
     * The same question as {@see permits()}, asked about many Parties at once.
     *
     * **Identical predicate, one query.** It is `scope()` again — the single
     * implementation of the rule — so a batched answer cannot disagree with the
     * per-record one; only the number of round trips differs. Archived Parties
     * are excluded here exactly as they are there, because both go through the
     * soft-deleted-aware `Party::query()`.
     *
     * This exists because a per-row `permits()` is an N+1 whenever a collection
     * spans several Parties: the actor's effective access is the same for every
     * row, so resolving and re-querying per row is repeated work. Measured on
     * the reverse Individual → Companies view, the per-row form cost two extra
     * queries for every additional relationship.
     *
     * Returns a set keyed by Party id, so a caller tests membership rather than
     * scanning a list.
     *
     * @param  array<int, string>  $partyIds
     * @return array<string, true>
     */
    public function reachablePartyKeys(array $partyIds, User $actor, EffectiveAccess $access): array
    {
        $partyIds = array_values(array_unique(array_filter($partyIds)));

        if ($partyIds === []) {
            return [];
        }

        return $this->scope(Party::query()->whereIn('id', $partyIds), $actor, $access)
            ->pluck('id')
            ->mapWithKeys(fn (string $id): array => [$id => true])
            ->all();
    }

    /**
     * May the actor create a Party in this Office?
     *
     * Creation has no target record, so the intended destination Office is the
     * subject. `ALL` may create anywhere the API exposes; `OFFICE` may create
     * only in the actor's own. This is a backend decision — office selection is
     * never a frontend-only rule.
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
     * Exact membership of `ALL`, never a comparison (D-028). Used by form
     * metadata so the Office choices offered are exactly the ones
     * {@see permitsCreationIn()} would accept — a dropdown that offers a
     * destination the Policy then refuses is a trap, not a feature.
     */
    public function reachesAllOffices(EffectiveAccess $access): bool
    {
        return in_array(DataScope::ALL, $this->usable($access), true);
    }

    /**
     * Does the actor hold this permission at any scope that reaches a Party?
     *
     * Used for list-level abilities: a grant carrying only OWN, ASSIGNED, or
     * TEAM reaches nothing, so it is refused outright rather than returning a
     * reliably empty page.
     */
    public function hasUsableScope(EffectiveAccess $access): bool
    {
        return $this->usable($access) !== [];
    }

    /**
     * The granted scopes that mean something for a Party.
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
