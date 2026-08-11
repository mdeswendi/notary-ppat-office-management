<?php

namespace App\Domains\Authorization\Actions;

use App\Domains\Authorization\PermissionRegistry;
use Spatie\Permission\Models\Role;

/**
 * Creates one role definition and nothing else.
 *
 * A new role deliberately starts with **zero capability**: no permissions, no
 * Data Scope rows, no members. That is not an omission to fix later in the same
 * request — granting anything on creation would mean inventing a default
 * capability set, and the resolver treats a permission without a scope row as no
 * grant at all (D-039), so a half-configured role would be a role that quietly
 * does nothing while appearing to do something.
 *
 * Capability is assigned in M1.6 through the permission matrix.
 *
 * The guard is fixed to the registry's own. It is never taken from request
 * input: a role on a guard nothing authenticates against would be invisible to
 * every check in the system while still looking real in the list.
 *
 * `PermissionRegistry::GUARD` rather than `config('auth.defaults.guard')` — the
 * latter reads `sanctum` inside an authenticated request, which would create
 * roles nothing could ever grant (D-046).
 */
class CreateRole
{
    public function handle(string $name): Role
    {
        return Role::create([
            'name' => $name,
            'guard_name' => PermissionRegistry::GUARD,
        ]);
    }
}
