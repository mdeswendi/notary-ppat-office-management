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
     * The flag exists to stop an administrator granting a capability and
     * wondering why nothing happens. It therefore covers permissions sitting
     * *inside a module the interface otherwise presents as working* — not every
     * unimplemented code. `projects.create` needs no flag because Projects is
     * absent from the navigation entirely (D-064); `security.settings.view`
     * does, because its neighbours `security.sessions.*` and
     * `security.mfa.manage` are live, so the namespace looks implemented.
     *
     * `users.reset_password` was here from M1.5 until M1.9 built the flow
     * (O-028, D-071). Removing it is the point of keeping this list honest: a
     * permanently stale "deferred" badge trains people to ignore the badge.
     *
     * `security.settings.*` remains deferred because no global security-settings
     * surface exists — verified against the route table, not assumed.
     *
     * **`companies.*` joined the list at M2.2**, and the reason is the flag's
     * whole purpose rather than an oversight. Before M2.2 the Party module was
     * absent from navigation, so `companies.view` needed no badge for the same
     * reason `projects.create` does not. M2.2 ships Individuals — navigation now
     * shows "Clients & Parties", the namespace looks implemented, and an
     * administrator granting `companies.view` would reasonably expect something.
     * Nothing happens: Company surfaces are M2.3 and M2.4.
     *
     * `parties.*` and `parties.identity.*` are deliberately **not** here. M2.2
     * gives every one of them a reachable route, and a test checks that claim
     * against the router rather than trusting this list.
     */
    private const DEFERRED = [
        'security.settings.view',
        'security.settings.manage',
        'companies.view',
        'companies.create',
        'companies.update',
        'companies.archive',
        'companies.management.view',
        'companies.management.update',
        'companies.shareholders.view',
        'companies.shareholders.update',
    ];
}
