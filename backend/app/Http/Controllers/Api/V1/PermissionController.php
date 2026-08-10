<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

/**
 * The canonical permission catalogue, for configuring roles against.
 *
 * Read-only. Permissions are created by `permissions:sync` from the registry,
 * never through the API — the registry is the source of truth, and an endpoint
 * that could add to it would make the table the source instead (D-035).
 *
 * Serves **only canonical permissions**. Rows the sync deliberately preserved
 * but no longer recognizes (D-036) are absent: the resolver refuses them
 * anyway, so offering one as a choice would let an administrator build a grant
 * that silently does nothing.
 */
class PermissionController extends Controller
{
    public function __construct(private readonly PermissionScopeRules $scopeRules) {}

    /**
     * The catalogue, grouped as the documentation groups it, with the scopes
     * each permission may be assigned at.
     *
     * `allowed_scopes` comes from the same rules the write endpoint enforces, so
     * the matrix cannot offer a scope that would then be rejected. `TEAM` never
     * appears (D-042).
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        // Which canonical permissions actually exist as rows. A registry entry
        // with no row grants nothing until the sync is run (D-039), and saying
        // so is more useful than hiding it.
        $synchronized = Permission::query()
            ->where('guard_name', PermissionRegistry::GUARD)
            ->pluck('name')
            ->flip();

        $groups = [];

        foreach (PermissionRegistry::groups() as $group => $codes) {
            $permissions = [];

            foreach ($codes as $code) {
                $permissions[] = [
                    'code' => $code,
                    'allowed_scopes' => array_map(
                        fn ($scope): string => $scope->value,
                        $this->scopeRules->allowedFor($code),
                    ),
                    'synchronized' => $synchronized->has($code),
                    // Registered and configurable, but no endpoint honours it
                    // yet. Saying so beats an administrator granting it and
                    // wondering why nothing happens — see O-028.
                    'deferred' => in_array($code, self::DEFERRED, true),
                ];
            }

            $groups[] = ['group' => $group, 'permissions' => $permissions];
        }

        return response()->json([
            'data' => [
                'guard' => PermissionRegistry::GUARD,
                'groups' => $groups,
            ],
            'meta' => [
                'total' => PermissionRegistry::count(),
                'deferred' => self::DEFERRED,
            ],
        ]);
    }

    /**
     * Canonical permissions with no implementation behind them yet.
     *
     * `users.reset_password` is registered because the capability is canonical,
     * but the reset *flow* is not defined anywhere, so M1.5 deferred it to M1.9
     * rather than invent an account-security design (O-028, D-051).
     */
    private const DEFERRED = ['users.reset_password'];
}
