<?php

namespace App\Domains\Identity;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\Office;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which users an actor's Data Scopes reach.
 *
 * A User is an **Office-owned** resource — `users.office_id` is required
 * (D-027) — so unlike a Role definition it has a field the narrower predicates
 * can match against. This class is where those predicates become SQL (D-049).
 *
 * Only three of the five scopes mean anything here:
 *
 *   ALL       every user in the deployment's Organization
 *   OFFICE    users whose office_id equals the actor's
 *   OWN       the actor themselves
 *   ASSIGNED  nothing — a User is not assigned to anybody
 *   TEAM      nothing — no Team entity exists (D-042)
 *
 * Union, never ranking (D-028). `{OWN, OFFICE}` matches the actor plus their
 * colleagues; `{OFFICE, ALL}` matches everyone, because `ALL` is an independent
 * predicate that happens to subsume the other, not because it outranks it.
 *
 * **The record check is the list query.** `permits()` runs the very same
 * constraint against a single key rather than reimplementing the rule, so
 * "which users appear in the list" and "which user may I open" cannot drift
 * apart — the failure mode where a record is hidden from a list but still
 * fetchable by id. The cost is one cheap indexed query.
 */
class UserVisibility
{
    /**
     * Scopes that select users to **read**.
     *
     * @return array<int, DataScope>
     */
    private const READABLE = [DataScope::ALL, DataScope::OFFICE, DataScope::OWN];

    /**
     * Scopes that select users to **administer**.
     *
     * `OWN` is deliberately absent. Holding `users.update` at `OWN` would
     * otherwise let anyone edit their own administrative record — including
     * moving themselves to another Office — which is self-service, not
     * administration. Profile editing is M1.8 and has its own capability.
     *
     * @return array<int, DataScope>
     */
    private const ADMINISTRABLE = [DataScope::ALL, DataScope::OFFICE];

    /**
     * Narrow a user query to what the actor may read.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeForReading(Builder $query, User $actor, EffectiveAccess $access): Builder
    {
        return $this->apply($query, $actor, $access, self::READABLE);
    }

    /**
     * Narrow a user query to what the actor may administer.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeForAdministration(Builder $query, User $actor, EffectiveAccess $access): Builder
    {
        return $this->apply($query, $actor, $access, self::ADMINISTRABLE);
    }

    public function permitsReading(User $actor, EffectiveAccess $access, User $target): bool
    {
        return $this->scopeForReading($this->single($target), $actor, $access)->exists();
    }

    public function permitsAdministration(User $actor, EffectiveAccess $access, User $target): bool
    {
        return $this->scopeForAdministration($this->single($target), $actor, $access)->exists();
    }

    /**
     * May the actor place a user in this Office?
     *
     * `ALL` reaches any Office; `OFFICE` reaches only the actor's own. The
     * Office must also be active — an inactive Office is being retired, and
     * assigning someone to it would be creating work in a place that is closing
     * (D-049).
     */
    public function permitsOfficeAssignment(User $actor, EffectiveAccess $access, string $officeId): bool
    {
        $scopes = $this->usable($access, self::ADMINISTRABLE);

        if ($scopes === []) {
            return false;
        }

        if (! in_array(DataScope::ALL, $scopes, true) && $officeId !== $actor->office_id) {
            return false;
        }

        return $this->assignableOffices($actor, $access)->whereKey($officeId)->exists();
    }

    /**
     * The active Offices the actor may place a user in.
     *
     * @return Builder<Office>
     */
    public function assignableOffices(User $actor, EffectiveAccess $access): Builder
    {
        return $this->assignableOfficesForScopes($actor, $this->usable($access, self::ADMINISTRABLE));
    }

    /**
     * The same question asked of a scope set assembled from more than one
     * capability — a form needs the Offices the actor may use for *either*
     * creating or updating, which is the union of both grants (D-028).
     *
     * @param  array<int, DataScope>  $scopes
     * @return Builder<Office>
     */
    public function assignableOfficesForScopes(User $actor, array $scopes): Builder
    {
        $usable = array_values(array_filter(
            $scopes,
            fn (DataScope $scope): bool => in_array($scope, self::ADMINISTRABLE, true),
        ));

        // Inactive Offices are never offered: an Office being retired is not
        // somewhere to put a new colleague.
        $query = Office::query()->where('is_active', true);

        if ($usable === []) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array(DataScope::ALL, $usable, true)) {
            return $query;
        }

        return $query->whereKey($actor->office_id);
    }

    /**
     * @param  Builder<User>  $query
     * @param  array<int, DataScope>  $applicable
     * @return Builder<User>
     */
    private function apply(Builder $query, User $actor, EffectiveAccess $access, array $applicable): Builder
    {
        $scopes = $this->usable($access, $applicable);

        // No usable predicate is not "no restriction" — it is no access. A
        // grant carrying only ASSIGNED or TEAM reaches no user at all.
        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }

        // ALL imposes no record restriction, so it short-circuits the rest.
        if (in_array(DataScope::ALL, $scopes, true)) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (BuilderContract $inner) use ($scopes, $actor, $table): void {
            foreach ($scopes as $scope) {
                match ($scope) {
                    DataScope::OFFICE => $inner->orWhere($table.'.office_id', $actor->office_id),
                    DataScope::OWN => $inner->orWhere($table.'.id', $actor->getKey()),
                    default => null,
                };
            }
        });
    }

    /**
     * The granted scopes that mean something for this operation.
     *
     * @param  array<int, DataScope>  $applicable
     * @return array<int, DataScope>
     */
    private function usable(EffectiveAccess $access, array $applicable): array
    {
        if (! $access->granted) {
            return [];
        }

        return array_values(array_filter(
            $access->scopes,
            fn (DataScope $scope): bool => in_array($scope, $applicable, true),
        ));
    }

    /**
     * @return Builder<User>
     */
    private function single(User $target): Builder
    {
        return User::query()->whereKey($target->getKey());
    }
}
