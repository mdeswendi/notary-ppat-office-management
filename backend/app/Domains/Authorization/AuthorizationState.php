<?php

namespace App\Domains\Authorization;

/**
 * Everything needed to decide one user's access, loaded from the database and
 * held as plain data.
 *
 * This exists so a decision can be made **without touching the database**, which
 * is what lets one permission and all 171 share the same decision function
 * (D-061). The state is loaded once — for a single permission or for the whole
 * registry — and the rule is applied to it identically either way.
 *
 * Values are raw strings exactly as stored. Nothing is parsed or normalized
 * here: interpreting `effect` and `scope` is the decision's job, and it must
 * treat an unrecognized value as a denial rather than a surprise.
 */
final class AuthorizationState
{
    /**
     * @param  array<string, true>  $existingPermissions  canonical names with a row on the registry guard
     * @param  array<string, array{effect: ?string, scope: ?string}>  $activeOverrides  by permission name
     * @param  array<string, array<int, string>>  $roleScopes  permission name => raw scope strings
     */
    public function __construct(
        private readonly array $existingPermissions,
        private readonly array $activeOverrides,
        private readonly array $roleScopes,
    ) {}

    public function permissionExists(string $permission): bool
    {
        return isset($this->existingPermissions[$permission]);
    }

    /**
     * The override in force for this permission, if any. Expired ones are never
     * loaded, so their absence here *is* the expiry rule being applied.
     *
     * @return array{effect: ?string, scope: ?string}|null
     */
    public function activeOverride(string $permission): ?array
    {
        return $this->activeOverrides[$permission] ?? null;
    }

    /**
     * Raw scope values granted through roles that genuinely hold the permission.
     *
     * @return array<int, string>
     */
    public function roleScopes(string $permission): array
    {
        return $this->roleScopes[$permission] ?? [];
    }
}
