<?php

namespace App\Domains\Authorization;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reconciles the canonical registry into the permissions table.
 *
 * Extracted from the M1.2 Artisan command so the bootstrap can reuse it
 * in-process. Shelling out to another `artisan` invocation would run outside
 * the caller's transaction, which is exactly what bootstrap must not do
 * (D-059). `permissions:sync` now calls this and behaves as it always did.
 *
 * Additive and idempotent, unchanged from D-036: it creates what the registry
 * declares and is missing, never truncates, prunes, renames, or reassigns, and
 * reports rows it does not recognize rather than deleting them.
 */
class SyncCanonicalPermissions
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function handle(): SyncCanonicalPermissionsResult
    {
        $guard = PermissionRegistry::GUARD;

        // Read through a cold cache; a stale collection would make the
        // "already present" check answer from a snapshot rather than the table.
        $this->registrar->forgetCachedPermissions();

        $permissionClass = $this->registrar->getPermissionClass();

        $canonical = PermissionRegistry::all();

        $existing = $permissionClass::query()
            ->where('guard_name', $guard)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($canonical, $existing));
        $unmanaged = array_values(array_diff($existing, $canonical));

        sort($missing);
        sort($unmanaged);

        // One transaction for the whole batch: a partially applied permission
        // set is worse than none, because role configuration would then be
        // designed against a surface that only partly exists.
        DB::transaction(function () use ($permissionClass, $missing, $guard): void {
            foreach ($missing as $name) {
                $permissionClass::create(['name' => $name, 'guard_name' => $guard]);
            }
        });

        $this->registrar->forgetCachedPermissions();

        return new SyncCanonicalPermissionsResult(
            guard: $guard,
            canonical: $canonical,
            created: $missing,
            unmanaged: $unmanaged,
        );
    }
}
