<?php

namespace App\Domains\Authorization\Actions;

use App\Domains\Authorization\AuthorizationContinuity;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Models\RolePermissionScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Replaces a role's entire permission configuration.
 *
 * Complete replacement rather than incremental edits: the matrix shows the whole
 * configuration at once, so saving it should mean "this is the configuration",
 * not "apply these deltas to whatever is there now". Omitted permissions are
 * revoked.
 *
 * **A grant and its Data Scope are one operation** (D-053). Spatie's
 * `role_has_permissions` row and our `role_permission_scopes` row are written
 * and removed together inside a single transaction, because the resolver treats
 * a grant without scope metadata as no grant at all (D-039) — so a half-applied
 * save would produce a role that looks configured and does nothing. Removing a
 * grant removes its scope row for the same reason: an orphan scope row describes
 * a grant that no longer exists.
 *
 * The whole thing runs inside {@see AuthorizationContinuity}, so a save that
 * would remove the last person able to administer authorization is rolled back
 * rather than committed (D-056).
 *
 * Spatie's cache is cleared after a successful commit; leaving it warm would
 * mean the package answering from configuration that no longer exists.
 */
class ReplaceRolePermissions
{
    public function __construct(
        private readonly AuthorizationContinuity $continuity,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @param  array<int, array{code: string, scope: DataScope}>  $grants
     */
    public function handle(Role $role, array $grants): void
    {
        $this->continuity->protecting(function () use ($role, $grants): void {
            $permissionIds = $this->permissionIds(array_column($grants, 'code'));

            // The package's own sync handles role_has_permissions, including
            // removing what is no longer granted.
            $role->syncPermissions(array_values($permissionIds));

            // Scope metadata is ours, so it is replaced explicitly. Deleting
            // first keeps the result identical whether a grant was added,
            // removed, or merely re-scoped.
            RolePermissionScope::query()->where('role_id', $role->getKey())->delete();

            $rows = [];
            $now = now();

            foreach ($grants as $grant) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'role_id' => $role->getKey(),
                    'permission_id' => $permissionIds[$grant['code']],
                    'scope' => $grant['scope']->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('role_permission_scopes')->insert($rows);
            }

            // Inside the transaction so the continuity check that follows reads
            // the configuration this save actually produced.
            $this->registrar->forgetCachedPermissions();
        });

        $this->registrar->forgetCachedPermissions();
    }

    /**
     * Map canonical names to their package ids, on the registry's guard.
     *
     * @param  array<int, string>  $codes
     * @return array<string, int>
     */
    private function permissionIds(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = DB::table(config('permission.table_names.permissions'))
            ->where('guard_name', PermissionRegistry::GUARD)
            ->whereIn('name', $codes)
            ->pluck('id', 'name');

        return $rows->all();
    }
}
