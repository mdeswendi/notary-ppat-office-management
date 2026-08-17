<?php

namespace App\Domains\Authorization;

use App\Domains\Authorization\Enums\AccessSource;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Support\Facades\DB;

/**
 * The one place that answers "which permission does this user hold, and at
 * which Data Scopes" (docs/07_SECURITY_RULES.md section 10).
 *
 * Every domain Policy consumes this, and since M1.7 so does the current-user
 * payload the interface renders from. Controllers must never work out Data
 * Scope independently — divergent copies of an authorization rule are how holes
 * appear quietly.
 *
 * What this does **not** answer: whether the user may touch one particular
 * record. That needs ownership fields, assignment relationships, record state,
 * and legal workflow rules, none of which exist yet.
 *
 * **One rule, two entry points.** {@see resolve()} answers for a single
 * permission and {@see resolveAll()} for the whole registry, but both load
 * plain {@see AuthorizationState} and hand it to the same private `decide()`.
 * Nothing about allow/deny, scopes, or ordering is written twice, so the bulk
 * projection cannot drift from the check that guards an endpoint (D-061) — a
 * test asserts they agree for every canonical permission on a deliberately
 * awkward fixture.
 *
 * The whole algorithm is fail-closed. Every branch that cannot produce a
 * confident grant produces a denial, including branches that only trigger on
 * corrupt data.
 *
 * Reads go to the database directly rather than through Spatie's cached
 * permission collection, so an authorization change is visible on the next
 * check. Results are deliberately not cached: role and override management now
 * exist, and a stale authorization cache fails in the direction that grants
 * access.
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

        return $this->decide($permission, $this->loadState($user, [$permission]));
    }

    /**
     * Every canonical permission this user effectively holds.
     *
     * Denied permissions are absent from the result rather than present and
     * empty: the caller is asking what somebody can do, and a denial is not a
     * capability with nothing in it.
     *
     * Keys follow the registry's canonical order, so the payload is stable
     * between requests.
     *
     * @return array<string, EffectiveAccess>
     */
    public function resolveAll(User $user): array
    {
        $canonical = PermissionRegistry::all();

        // Loaded once for the whole registry — four queries, regardless of how
        // many permissions exist. Resolving each name separately would be one
        // round trip per canonical permission to answer one question.
        $state = $this->loadState($user, $canonical);

        $granted = [];

        foreach ($canonical as $permission) {
            $access = $this->decide($permission, $state);

            if ($access->granted) {
                $granted[$permission] = $access;
            }
        }

        return $granted;
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
     * The decision itself. Pure: it reads the loaded state and nothing else.
     *
     * Being the only implementation of these rules is the point — see the class
     * docblock.
     */
    private function decide(string $permission, AuthorizationState $state): EffectiveAccess
    {
        // Step 1 again, because resolveAll() iterates canonical names but a
        // caller could reach here with anything.
        if (! PermissionRegistry::has($permission)) {
            return EffectiveAccess::denied();
        }

        // Step 2 — a canonical name with no row grants nothing. The resolver
        // does not create the row: an authorization check must never mutate
        // the registry, so a missing permission is an operator's unrun sync,
        // not something to paper over mid-request.
        if (! $state->permissionExists($permission)) {
            return EffectiveAccess::denied();
        }

        // Step 3 — an active override decides on its own (D-029). Expired ones
        // were never loaded, which is how check-time expiry is applied.
        $override = $state->activeOverride($permission);

        if ($override !== null) {
            return $this->decideOverride($override);
        }

        // Steps 4 to 6 — role grants, scopes unioned (D-028). Unrecognized
        // stored values are dropped rather than guessed at, so a corrupt row
        // costs its own grant and nothing else. An empty union is a denial,
        // which EffectiveAccess::fromRoles() enforces.
        $scopes = [];

        foreach ($state->roleScopes($permission) as $raw) {
            $scope = DataScope::tryFrom($raw);

            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return EffectiveAccess::fromRoles($scopes);
    }

    /**
     * Turn an active override into a result.
     *
     * Values are parsed with `tryFrom` rather than trusted: a corrupt value must
     * fail closed, not raise from inside an authorization check.
     *
     * @param  array{effect: ?string, scope: ?string}  $override
     */
    private function decideOverride(array $override): EffectiveAccess
    {
        $effect = $override['effect'] === null
            ? null
            : UserPermissionEffect::tryFrom($override['effect']);

        // DENY, or an effect this application does not recognize. Both deny,
        // and neither falls through to role resolution — a row that exists and
        // cannot be understood must not quietly become "no override".
        if ($effect !== UserPermissionEffect::ALLOW) {
            return EffectiveAccess::denied(AccessSource::OVERRIDE);
        }

        $scope = $override['scope'] === null
            ? null
            : DataScope::tryFrom($override['scope']);

        // ALLOW needs an authoritative scope. Reading a missing one as
        // unrestricted would turn a data defect into a privilege escalation.
        if ($scope === null) {
            return EffectiveAccess::denied(AccessSource::OVERRIDE);
        }

        return EffectiveAccess::fromOverride($scope);
    }

    /**
     * Load the authorization state for a set of canonical permissions.
     *
     * Four queries whatever the set's size, so the projection does not degrade
     * as the registry grows.
     *
     * @param  array<int, string>  $permissions
     */
    private function loadState(User $user, array $permissions): AuthorizationState
    {
        $guard = PermissionRegistry::GUARD;

        $permissionsTable = config('permission.table_names.permissions');

        // name => id, for the requested canonical names that actually exist.
        $ids = DB::table($permissionsTable)
            ->where('guard_name', $guard)
            ->whereIn('name', $permissions)
            ->pluck('id', 'name');

        if ($ids->isEmpty()) {
            return new AuthorizationState([], [], []);
        }

        $existing = $ids->map(fn (): bool => true)->all();

        $byId = array_flip($ids->all());

        return new AuthorizationState(
            existingPermissions: $existing,
            activeOverrides: $this->loadOverrides($user, $ids->values()->all(), $byId),
            roleScopes: $this->loadRoleScopes($user, $ids->values()->all(), $byId, $guard),
        );
    }

    /**
     * Overrides currently in force, keyed by permission name.
     *
     * Expiry is evaluated here, at check time, by binding the current instant
     * into the query — never by trusting a cleanup job to have removed the row
     * (D-029). The comparison is strict, so an override expiring exactly now is
     * already expired.
     *
     * At most one row can exist per (user, permission): the table is unique on
     * that pair.
     *
     * @param  array<int, int|string>  $permissionIds
     * @param  array<int|string, string>  $byId
     * @return array<string, array{effect: ?string, scope: ?string}>
     */
    private function loadOverrides(User $user, array $permissionIds, array $byId): array
    {
        $rows = UserPermissionOverride::query()
            ->where('user_id', $user->getKey())
            ->whereIn('permission_id', $permissionIds)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->toBase()
            ->get(['permission_id', 'effect', 'scope']);

        $overrides = [];

        foreach ($rows as $row) {
            $name = $byId[$row->permission_id] ?? null;

            if ($name !== null) {
                $overrides[$name] = [
                    'effect' => $row->effect === null ? null : (string) $row->effect,
                    'scope' => $row->scope === null ? null : (string) $row->scope,
                ];
            }
        }

        return $overrides;
    }

    /**
     * Data Scopes granted through the roles this user actually holds.
     *
     * Three conditions must all hold for a scope to count: the user holds the
     * role, that role holds the permission, and that role has scope metadata
     * for it. A role that holds the permission with no scope row contributes
     * nothing — Data Scope is required metadata, and treating its absence as
     * `ALL` would be a privilege escalation.
     *
     * `$user->roles()` reads the role pivot only. Spatie's direct user
     * permissions are never consulted anywhere in this class — they are package
     * infrastructure, not a first-party grant path (D-029, D-041).
     *
     * @param  array<int, int|string>  $permissionIds
     * @param  array<int|string, string>  $byId
     * @return array<string, array<int, string>>
     */
    private function loadRoleScopes(User $user, array $permissionIds, array $byId, string $guard): array
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

        $rows = RolePermissionScope::query()
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permissionIds)
            ->whereExists(function ($query) use ($rolePermissionsTable, $scopesTable): void {
                $query->selectRaw('1')
                    ->from($rolePermissionsTable)
                    ->whereColumn($rolePermissionsTable.'.role_id', $scopesTable.'.role_id')
                    ->whereColumn($rolePermissionsTable.'.permission_id', $scopesTable.'.permission_id');
            })
            ->toBase()
            ->get(['permission_id', 'scope']);

        $scopes = [];

        foreach ($rows as $row) {
            $name = $byId[$row->permission_id] ?? null;

            if ($name !== null) {
                $scopes[$name][] = (string) $row->scope;
            }
        }

        return $scopes;
    }
}
