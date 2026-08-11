<?php

namespace App\Domains\Authorization\Actions;

use App\Domains\Authorization\AuthorizationContinuity;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Replaces which roles a user holds.
 *
 * Complete replacement: the interface shows the whole membership, so saving it
 * means "these are their roles". Omitted roles are removed.
 *
 * This is **permission administration**, not user administration — changing
 * somebody's roles changes what they can do — so it is guarded by
 * `permissions.assign`, not `users.update` (D-055). Someone who may correct a
 * colleague's phone number should not thereby be able to make them an
 * administrator.
 *
 * Touches `model_has_roles` and nothing else. Role permissions, Data Scope
 * metadata, direct package permissions, permission overrides, and every profile
 * field are left exactly as they were; tests assert each of them across a
 * replacement.
 *
 * Runs inside {@see AuthorizationContinuity}, so unassigning the last person who
 * can administer authorization is rolled back rather than committed (D-056).
 */
class ReplaceUserRoles
{
    public function __construct(
        private readonly AuthorizationContinuity $continuity,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @param  array<int, Role>  $roles
     */
    public function handle(User $user, array $roles): void
    {
        $this->continuity->protecting(function () use ($user, $roles): void {
            $user->syncRoles($roles);

            $this->registrar->forgetCachedPermissions();
        });

        $this->registrar->forgetCachedPermissions();
    }
}
