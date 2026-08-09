<?php

namespace App\Domains\Authorization;

use App\Domains\Authorization\Enums\AccessSource;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The one place that answers "which permission does this user hold, and at
 * which Data Scopes" (docs/07_SECURITY_RULES.md section 10).
 *
 * Every future domain Policy consumes this. Controllers must never work out
 * Data Scope independently — divergent copies of an authorization rule are how
 * holes appear quietly.
 *
 * What this does **not** answer: whether the user may touch one particular
 * record. That needs ownership fields, assignment relationships, record state,
 * and legal workflow rules, none of which exist yet, and inventing them here
 * would mean guessing conventions across domains that have not been designed.
 *
 * The whole algorithm is fail-closed. Every branch that cannot produce a
 * confident grant produces a denial, including branches that only trigger on
 * corrupt data.
 *
 * Reads go to the database directly rather than through Spatie's cached
 * permission collection, so an authorization change is visible on the next
 * check. Results are deliberately not cached here (M1.3): role and override
 * management do not exist yet, and an invalidation rule written before them
 * would be one more security surface with nothing to validate it against.
 */
class EffectiveAccessResolver
{
    /**
     * Resolve one user's effective access to one canonical permission.
     */
    public function resolve(User $user, string $permission): EffectiveAccess
    {
        // Step 1 — the registry is the source of truth, not the table.
        //
        // permissions:sync never prunes (D-036), so the table can hold names
        // the application no longer recognizes. A leftover row must not become
        // a live capability just by existing.
        if (! PermissionRegistry::has($permission)) {
            return EffectiveAccess::denied();
        }

        // Fixed, never read from `auth.defaults.guard`: the authentication
        // middleware rewrites that value mid-request, and following it would
        // send every authenticated request looking for permissions on a guard
        // no row was written for. See PermissionRegistry::GUARD and D-046.
        $guard = PermissionRegistry::GUARD;

        // Step 2 — a canonical name with no row grants nothing. The resolver
        // does not create the row: an authorization check must never mutate
        // the registry, so a missing permission is an operator's unrun sync,
        // not something to paper over mid-request.
        $permissionId = $this->permissionId($permission, $guard);

        if ($permissionId === null) {
            return EffectiveAccess::denied();
        }

        // Step 3 — an active override decides on its own (D-029).
        $override = $this->activeOverride($user, $permissionId);

        if ($override !== null) {
            return $this->resolveOverride($override);
        }

        // Steps 4 to 6 — role grants, scopes unioned (D-028). An empty union
        // is a denial, which EffectiveAccess::fromRoles() enforces.
        return EffectiveAccess::fromRoles($this->roleScopes($user, $permissionId, $guard));
    }

    /**
     * May this user exercise a permission over a **deployment-global** record?
     *
     * Some records belong to nobody: a Role definition is not owned, assigned,
     * office-held, or part of a team. `ALL` is the only Data Scope that
     * describes reaching them, so a grant limited to `OWN`, `ASSIGNED`,
     * `TEAM`, or `OFFICE` cannot manage one — those predicates have nothing to
     * match against.
     *
     * This is resource-context validation, **not** a ranking. Nothing here
     * says `ALL` outranks `OFFICE`; it says this particular kind of record
     * needs the unrestricted predicate. An office-scoped grant remains fully
     * valid for office-scoped records (D-028, D-044).
     *
     * Presence is what counts, so `{OFFICE, ALL}` passes — the user does hold
     * `ALL` — while `{OFFICE}` alone does not.
     */
    public function allowsGlobally(User $user, string $permission): bool
    {
        $access = $this->resolve($user, $permission);

        return $access->granted && $access->hasScope(DataScope::ALL);
    }

    /**
     * The permission row's key, or null when the canonical name has none.
     */
    private function permissionId(string $permission, string $guard): int|string|null
    {
        return DB::table(config('permission.table_names.permissions'))
            ->where('name', $permission)
            ->where('guard_name', $guard)
            ->value('id');
    }

    /**
     * The user's override for this permission, if one is currently in force.
     *
     * Expiry is evaluated here, at check time, by binding the current instant
     * into the query — never by trusting a cleanup job to have removed the row
     * (D-029). The comparison is strict, so an override expiring exactly now is
     * already expired.
     *
     * At most one row can match: the table is unique on (user_id, permission_id).
     */
    private function activeOverride(User $user, int|string $permissionId): ?object
    {
        return UserPermissionOverride::query()
            ->where('user_id', $user->getKey())
            ->where('permission_id', $permissionId)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->toBase()
            ->first(['effect', 'scope']);
    }

    /**
     * Turn an active override row into a result.
     *
     * Values are read raw and parsed with `tryFrom` rather than through the
     * model's enum casts: a corrupt value must fail closed, not raise a
     * ValueError from inside an authorization check.
     */
    private function resolveOverride(object $override): EffectiveAccess
    {
        $effect = UserPermissionEffect::tryFrom((string) $override->effect);

        // DENY, or an effect this application does not recognize. Both deny,
        // and neither falls through to role resolution — a row that exists and
        // cannot be understood must not quietly become "no override".
        if ($effect !== UserPermissionEffect::ALLOW) {
            return EffectiveAccess::denied(AccessSource::OVERRIDE);
        }

        $scope = $override->scope === null
            ? null
            : DataScope::tryFrom((string) $override->scope);

        // ALLOW needs an authoritative scope. Reading a missing one as
        // unrestricted would turn a data defect into a privilege escalation.
        if ($scope === null) {
            return EffectiveAccess::denied(AccessSource::OVERRIDE);
        }

        return EffectiveAccess::fromOverride($scope);
    }

    /**
     * Every Data Scope this user's roles grant for this permission.
     *
     * Three conditions must all hold for a scope to count: the user holds the
     * role, that role holds the permission, and that role has scope metadata
     * for it. A role that holds the permission with no scope row contributes
     * nothing — Data Scope is required metadata, and treating its absence as
     * `ALL` would be a privilege escalation.
     *
     * Two queries regardless of how many roles the user holds, so resolution
     * does not degrade as role membership grows.
     *
     * `$user->roles()` reads the role pivot only. Spatie's direct user
     * permissions are never consulted anywhere in this class — they are package
     * infrastructure, not a first-party grant path (D-029).
     *
     * @return list<DataScope>
     */
    private function roleScopes(User $user, int|string $permissionId, string $guard): array
    {
        $rolesTable = config('permission.table_names.roles');
        $rolePermissionsTable = config('permission.table_names.role_has_permissions');
        $scopesTable = (new RolePermissionScope)->getTable();

        $roleIds = $user->roles()
            ->where($rolesTable.'.guard_name', $guard)
            ->pluck($rolesTable.'.id');

        if ($roleIds->isEmpty()) {
            return [];
        }

        $values = RolePermissionScope::query()
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permissionId)
            ->whereExists(function (QueryBuilder $query) use ($rolePermissionsTable, $scopesTable, $permissionId): void {
                $query->selectRaw('1')
                    ->from($rolePermissionsTable)
                    ->whereColumn($rolePermissionsTable.'.role_id', $scopesTable.'.role_id')
                    ->where($rolePermissionsTable.'.permission_id', $permissionId);
            })
            ->toBase()
            ->pluck('scope');

        // Unrecognized stored values are dropped rather than guessed at, so a
        // corrupt row costs its own grant and nothing else.
        return $values
            ->map(fn (mixed $value): ?DataScope => DataScope::tryFrom((string) $value))
            ->filter()
            ->values()
            ->all();
    }
}
