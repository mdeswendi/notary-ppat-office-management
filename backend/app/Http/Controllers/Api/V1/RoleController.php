<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\Actions\CreateRole;
use App\Domains\Authorization\Actions\DeleteRole;
use App\Domains\Authorization\Actions\RenameRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Policies\RolePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Permission\Models\Role;

/**
 * Role definitions.
 *
 * Thin by design (CLAUDE.md section 35): authorize, take validated input, call
 * an action, return a resource. Every rule worth protecting lives in
 * {@see RolePolicy} and the action classes, where it can be read
 * and tested without an HTTP request.
 *
 * Deliberately absent: permission assignment, Data Scope assignment, and member
 * management. Those are separate capabilities (`permissions.assign`,
 * `users.update`) belonging to later milestones, and nesting them here would
 * put them behind `roles.update`, which is not what the permission catalogue
 * says.
 */
class RoleController extends Controller
{
    /**
     * Every role, ordered by name.
     *
     * Not paginated. Roles are deployment-global configuration whose count is
     * bounded by deliberate administrative action — nine by default — rather
     * than an operational dataset that grows with the business, which is what
     * `docs/06_API_CONVENTIONS.md` section 9 is guarding against. A `meta.total`
     * is still returned so the collection shape matches the convention.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()->orderBy('name')->get();

        return RoleResource::collection($roles)
            ->additional(['meta' => ['total' => $roles->count()]]);
    }

    public function store(StoreRoleRequest $request, CreateRole $createRole): JsonResponse
    {
        $this->authorize('create', Role::class);

        $role = $createRole->handle($request->validated('name'));

        return RoleResource::make($role)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Role $role): RoleResource
    {
        $this->authorize('view', $role);

        return RoleResource::make($role);
    }

    public function update(UpdateRoleRequest $request, Role $role, RenameRole $renameRole): RoleResource
    {
        $this->authorize('update', $role);

        return RoleResource::make($renameRole->handle($role, $request->validated('name')));
    }

    /**
     * Refuses with 409 when anyone still holds the role — see {@see DeleteRole}.
     */
    public function destroy(Role $role, DeleteRole $deleteRole): JsonResponse
    {
        $this->authorize('delete', $role);

        $deleteRole->handle($role);

        return response()->json(status: 204);
    }
}
