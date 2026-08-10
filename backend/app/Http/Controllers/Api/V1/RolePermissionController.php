<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\Actions\ReplaceRolePermissions;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\ReplaceRolePermissionsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A role's permission configuration.
 *
 * Guarded by `permissions.view` and `permissions.assign` at the `ALL` scope
 * (D-054) — not by `roles.update`. Editing what a role *can do* is authorization
 * administration; editing its name is not, and conflating them would let anyone
 * who may rename a role also grant it anything.
 */
class RolePermissionController extends Controller
{
    /**
     * What this role is configured to do.
     *
     * A `role_has_permissions` row with no matching scope row is **not** a
     * configured grant — the resolver ignores it (D-039) — so it is reported
     * separately as malformed rather than shown as though it worked, and never
     * silently read as `ALL`. The M1.6 write path cannot produce one; legacy or
     * hand-edited data can.
     */
    public function index(Role $role): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        $scopes = DB::table('role_permission_scopes')
            ->join(
                config('permission.table_names.permissions').' as p',
                'p.id',
                '=',
                'role_permission_scopes.permission_id'
            )
            ->where('role_permission_scopes.role_id', $role->getKey())
            ->pluck('role_permission_scopes.scope', 'p.name');

        $granted = $role->permissions()->pluck('name');

        $configured = [];
        $malformed = [];

        foreach ($granted->sort()->values() as $code) {
            $scope = $scopes->get($code);

            // Missing scope metadata, a value the enum does not recognize, or a
            // name the registry no longer declares: each grants nothing, and
            // each needs an administrator to look rather than be tidied away.
            if ($scope === null || DataScope::tryFrom((string) $scope) === null || ! PermissionRegistry::has($code)) {
                $malformed[] = ['code' => $code, 'scope' => $scope, 'reason' => $this->reason($code, $scope)];

                continue;
            }

            $configured[] = ['code' => $code, 'scope' => $scope];
        }

        // Scope rows whose grant was removed outside this API describe nothing.
        foreach ($scopes as $code => $scope) {
            if (! $granted->contains($code)) {
                $malformed[] = ['code' => $code, 'scope' => $scope, 'reason' => 'SCOPE_WITHOUT_GRANT'];
            }
        }

        return response()->json([
            'data' => [
                'role' => ['id' => $role->getKey(), 'name' => $role->name],
                'permissions' => $configured,
                'malformed' => $malformed,
            ],
            'meta' => ['total' => count($configured)],
        ]);
    }

    /**
     * Replace the configuration wholesale.
     *
     * Grant and scope are written together, omitted permissions are revoked, and
     * the whole save is rolled back with 409 if it would leave nobody able to
     * administer authorization (D-053, D-056).
     */
    public function update(
        ReplaceRolePermissionsRequest $request,
        Role $role,
        ReplaceRolePermissions $replace,
    ): JsonResponse {
        $this->authorize('assign', Permission::class);

        $replace->handle($role, $request->grants());

        return $this->index($role);
    }

    private function reason(string $code, ?string $scope): string
    {
        if (! PermissionRegistry::has($code)) {
            return 'NOT_CANONICAL';
        }

        return $scope === null ? 'MISSING_SCOPE' : 'INVALID_SCOPE';
    }
}
