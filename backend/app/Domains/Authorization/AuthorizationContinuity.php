<?php

namespace App\Domains\Authorization;

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Exceptions\NoAuthorizationAdministratorWouldRemain;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps at least one person able to administer authorization.
 *
 * M1.6 makes the authorization configuration editable, which means it can be
 * edited into a state nobody can edit back: remove `permissions.assign` from the
 * only role that grants it, narrow its scope, or unassign the last
 * administrator, and the deployment is locked out of its own permission system
 * with no in-product recovery (D-056).
 *
 * So every mutation that touches role permissions, role scopes, or role
 * membership runs inside a transaction that ends by asking: does at least one
 * **active, non-deleted** user still resolve `permissions.assign` with the `ALL`
 * scope? If not, the transaction rolls back and the caller gets 409.
 *
 * The invariant is **capability-based, never name-based**. It does not look for
 * `SUPER_ADMIN` and does not care what any role is called — a custom role
 * satisfies it exactly as well, and holding the name satisfies it not at all
 * (D-032). Losing your own access is allowed, as long as somebody else keeps
 * theirs.
 *
 * Disabled and soft-deleted users do not count. An account that cannot log in
 * cannot administer anything, so treating it as a safety net would be
 * pretending.
 */
class AuthorizationContinuity
{
    public const PERMISSION = 'permissions.assign';

    public function __construct(private readonly EffectiveAccessResolver $resolver) {}

    /**
     * Run a mutation and keep it only if it did not remove the last
     * administrator.
     *
     * The check happens inside the same transaction as the change, so the
     * decision is made against the state the change actually produced rather
     * than a prediction of it.
     *
     * The invariant is that **this operation must not be what causes the loss**,
     * not that an administrator must exist unconditionally. A deployment that
     * already has none — before bootstrap, or in a test fixture that never
     * needed one — is not made worse by an unrelated change, and refusing every
     * such change would make an empty deployment inexplicably read-only. Since
     * no guarded operation can take the count from one to zero, it can never
     * reach zero this way in the first place.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $mutation
     * @return TReturn
     *
     * @throws NoAuthorizationAdministratorWouldRemain
     */
    public function protecting(callable $mutation): mixed
    {
        return DB::transaction(function () use ($mutation): mixed {
            $existedBefore = $this->administratorExists();

            $result = $mutation();

            if ($existedBefore && ! $this->administratorExists()) {
                throw new NoAuthorizationAdministratorWouldRemain;
            }

            return $result;
        });
    }

    /**
     * Does any active user still hold `permissions.assign` at `ALL`?
     */
    public function administratorExists(): bool
    {
        foreach ($this->candidates() as $user) {
            $access = $this->resolver->resolve($user, self::PERMISSION);

            if ($access->granted && $access->hasScope(DataScope::ALL)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Users who could conceivably qualify, so the resolver runs a handful of
     * times rather than once per account.
     *
     * The resolver can only grant through a role or an override, so a user with
     * neither cannot qualify and is safely skipped. The narrowing is a
     * shortlist, not a second implementation of the rule — every candidate is
     * still put through the real resolver, which is what makes overrides,
     * expiry, and missing scope metadata come out right.
     *
     * @return iterable<User>
     */
    private function candidates(): iterable
    {
        $permissionId = DB::table(config('permission.table_names.permissions'))
            ->where('name', self::PERMISSION)
            ->where('guard_name', PermissionRegistry::GUARD)
            ->value('id');

        if ($permissionId === null) {
            return [];
        }

        $rolePermissions = config('permission.table_names.role_has_permissions');
        $modelRoles = config('permission.table_names.model_has_roles');

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($modelRoles, $rolePermissions, $permissionId): void {
                $query
                    ->whereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from($modelRoles)
                        ->whereColumn($modelRoles.'.model_id', 'users.id')
                        ->where($modelRoles.'.model_type', (new User)->getMorphClass())
                        ->whereIn($modelRoles.'.role_id', fn ($roles) => $roles
                            ->select('role_id')
                            ->from($rolePermissions)
                            ->where('permission_id', $permissionId)))
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('user_permission_overrides')
                        ->whereColumn('user_permission_overrides.user_id', 'users.id')
                        ->where('user_permission_overrides.permission_id', $permissionId));
            })
            ->cursor();
    }
}
