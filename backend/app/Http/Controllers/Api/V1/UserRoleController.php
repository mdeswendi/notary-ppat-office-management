<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\Actions\ReplaceUserRoles;
use App\Domains\Authorization\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ReplaceUserRolesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Which roles a user holds.
 *
 * Guarded by `permissions.view` and `permissions.assign` at `ALL` — **not** by
 * `users.update` (D-055). Granting somebody a role changes what they can do, so
 * it is permission administration wherever it happens to appear in the
 * interface. An office manager who may correct a colleague's phone number must
 * not thereby be able to make them an administrator.
 *
 * A user may hold several roles; their effective scopes are the union (D-028).
 *
 * Direct package permissions are deliberately not exposed here or anywhere:
 * `model_has_permissions` stays package infrastructure, never a first-party
 * grant path (D-029, D-041).
 */
class UserRoleController extends Controller
{
    public function index(User $user): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        return $this->membership($user);
    }

    public function update(ReplaceUserRolesRequest $request, User $user, ReplaceUserRoles $replace): JsonResponse
    {
        $this->authorize('assign', Permission::class);

        $roles = Role::query()
            ->where('guard_name', PermissionRegistry::GUARD)
            ->whereIn('id', $request->roleIds())
            ->get()
            ->all();

        $replace->handle($user, $roles);

        return $this->membership($user->fresh());
    }

    private function membership(User $user): JsonResponse
    {
        $roles = $user->roles()
            ->where('guard_name', PermissionRegistry::GUARD)
            ->orderBy('name')
            ->get(['roles.id', 'roles.name']);

        return response()->json([
            'data' => [
                'user' => ['id' => $user->getKey(), 'name' => $user->name],
                'roles' => $roles->map(fn (Role $role): array => [
                    'id' => $role->getKey(),
                    'name' => $role->name,
                ])->all(),
            ],
            'meta' => ['total' => $roles->count()],
        ]);
    }
}
