<?php

namespace App\Domains\Authorization\Actions;

use App\Domains\Authorization\Exceptions\RoleIsAssigned;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Deletes a role that nobody holds.
 *
 * The guard exists because the package's `model_has_roles.role_id` cascades:
 * without a check, deleting a role would silently strip capability from every
 * person holding it, and the first anyone would notice is a user unable to do
 * their job. Removing a role must be a decision about a role, never an
 * unannounced decision about people.
 *
 * So an assigned role is refused, and the caller is told. Detaching users
 * automatically is deliberately not offered — that is a user-administration
 * act, and it belongs to whoever is managing those users, made explicitly.
 *
 * Any model type may hold a role, so the check reads the pivot table rather
 * than a `users` relation.
 *
 * Once the role is unheld, deleting it also removes its permission grants and
 * Data Scope rows through the existing foreign keys. Those describe the role;
 * with the role gone they describe nothing (D-038).
 *
 * Known limit: the check and the delete are not proof against a role being
 * assigned in the instant between them. Closing that would mean restricting
 * the package's own pivot, which M1.4 must not modify. No assignment path
 * exists yet in any case — it arrives in M1.5 — and this is recorded rather
 * than papered over.
 */
class DeleteRole
{
    public function handle(Role $role): void
    {
        DB::transaction(function () use ($role): void {
            if ($this->isHeldBySomeone($role)) {
                throw new RoleIsAssigned;
            }

            $role->delete();
        });
    }

    private function isHeldBySomeone(Role $role): bool
    {
        $pivot = config('permission.table_names.model_has_roles');
        $roleKey = config('permission.column_names.role_pivot_key') ?? 'role_id';

        return DB::table($pivot)->where($roleKey, $role->getKey())->exists();
    }
}
