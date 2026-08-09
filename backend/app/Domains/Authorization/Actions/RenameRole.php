<?php

namespace App\Domains\Authorization\Actions;

use Spatie\Permission\Models\Role;

/**
 * Changes a role's name, and only its name.
 *
 * Renaming must not move capability. Permission grants, Data Scope rows, and
 * memberships all reference `roles.id`, never the name, so nothing here touches
 * them — and because no authorization path anywhere compares a role name
 * (D-032), a rename cannot change what anybody may do.
 *
 * That property is worth stating plainly because it is easy to break: the day
 * someone writes `hasRole('PRINCIPAL')`, renaming a role starts silently
 * revoking access. Tests assert the three assignment tables are untouched.
 *
 * The guard is not writable here. It is not part of the name.
 */
class RenameRole
{
    public function handle(Role $role, string $name): Role
    {
        $role->name = $name;
        $role->save();

        return $role;
    }
}
