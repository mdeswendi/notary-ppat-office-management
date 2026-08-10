<?php

namespace App\Domains\Authorization;

use App\Domains\Authorization\Enums\DataScope;

/**
 * Which Data Scopes may be assigned to which permission.
 *
 * One source, consulted by the write endpoint and served to the interface, so
 * the matrix cannot offer a scope the backend would reject and the backend
 * cannot silently accept one the matrix never showed (D-053).
 *
 * It holds **no permission names of its own** — every answer is derived from
 * {@see PermissionRegistry}, which stays the only catalogue. What lives here is
 * the rule about a name's shape, not the name.
 *
 * **`TEAM` is never assignable.** It remains a canonical `DataScope` because the
 * vocabulary is fixed (D-042), but no Team entity exists, so a grant carrying it
 * could never be evaluated against a record. `02_MENU_AND_PERMISSIONS.md`
 * section 22 requires it be rejected in validation, and this is where that
 * happens.
 *
 * Only the rules the specification has actually settled are encoded. Everything
 * else gets the generic non-TEAM set rather than a restriction invented here —
 * a domain's Policy decides what its own permissions can mean, and none of those
 * domains exist yet.
 */
class PermissionScopeRules
{
    /**
     * Deployment-global authorization metadata: not owned, not assigned, not
     * office-held. `ALL` is the only predicate that can reach it (D-044).
     */
    private const GLOBAL_PREFIXES = ['roles.', 'permissions.'];

    /**
     * Reading users, which a person may legitimately do for themselves.
     */
    private const USER_READ = ['users.view'];

    /**
     * Administering users. `OWN` is excluded — editing your own administrative
     * record is self-service, not administration (D-049).
     */
    private const USER_ADMINISTRATION = [
        'users.create',
        'users.update',
        'users.disable',
        'users.reset_password',
    ];

    /**
     * The scopes assignable to a permission, in canonical order.
     *
     * @return array<int, DataScope>
     */
    public function allowedFor(string $permission): array
    {
        if ($this->matchesPrefix($permission, self::GLOBAL_PREFIXES)) {
            return [DataScope::ALL];
        }

        if (in_array($permission, self::USER_READ, true)) {
            return [DataScope::OWN, DataScope::OFFICE, DataScope::ALL];
        }

        if (in_array($permission, self::USER_ADMINISTRATION, true)) {
            return [DataScope::OFFICE, DataScope::ALL];
        }

        // Everything else, including every permission whose module is not built
        // yet. Deliberately permissive rather than guessed: narrowing it would
        // mean deciding what `notary.deeds.approve` at `OWN` means before the
        // Notary domain has been designed.
        return [DataScope::OWN, DataScope::ASSIGNED, DataScope::OFFICE, DataScope::ALL];
    }

    public function permits(string $permission, DataScope $scope): bool
    {
        return in_array($scope, $this->allowedFor($permission), true);
    }

    /**
     * The rules for the whole catalogue, keyed by permission name.
     *
     * @return array<string, array<int, string>>
     */
    public function all(): array
    {
        $rules = [];

        foreach (PermissionRegistry::all() as $permission) {
            $rules[$permission] = array_map(
                fn (DataScope $scope): string => $scope->value,
                $this->allowedFor($permission),
            );
        }

        return $rules;
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function matchesPrefix(string $permission, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($permission, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
