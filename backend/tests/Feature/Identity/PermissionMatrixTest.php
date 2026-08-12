<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Domains\Authorization\SyncCanonicalPermissions;
use App\Models\RolePermissionScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An authorization administrator: permissions.view and permissions.assign, both
 * at ALL, plus the canonical permission rows the matrix configures against.
 */
function matrixAdministrator(): User
{
    $user = User::factory()->create();

    app(SyncCanonicalPermissions::class)->handle();

    grantPermissionScope($user, 'permissions.view', DataScope::ALL);
    grantPermissionScope($user, 'permissions.assign', DataScope::ALL);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Canonical permission catalogue
|--------------------------------------------------------------------------
*/

it('rejects an unauthenticated request for the catalogue', function (): void {
    $this->getJson('/api/v1/permissions')->assertUnauthorized();
});

it('forbids the catalogue without permissions.view', function (): void {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/permissions')->assertForbidden();
});

it('forbids the catalogue at any scope narrower than ALL', function (DataScope $scope): void {
    // The permission catalogue is deployment-global; nothing narrower has a
    // record to match against.
    $user = User::factory()->create();
    grantPermissionScope($user, 'permissions.view', $scope);

    $this->actingAs($user)->getJson('/api/v1/permissions')->assertForbidden();
})->with([
    'OFFICE' => DataScope::OFFICE,
    'OWN' => DataScope::OWN,
    'ASSIGNED' => DataScope::ASSIGNED,
    'TEAM' => DataScope::TEAM,
]);

it('forbids the catalogue from a direct package permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(makePermission('permissions.view'));

    $this->actingAs($user)->getJson('/api/v1/permissions')->assertForbidden();
});

it('serves the whole canonical catalogue in registry order', function (): void {
    $response = $this->actingAs(matrixAdministrator())->getJson('/api/v1/permissions')->assertOk();

    $codes = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => array_column($group['permissions'], 'code'))
        ->all();

    $expected = collect(PermissionRegistry::groups())->flatten()->all();

    expect($codes)->toBe($expected)
        ->and($response->json('meta.total'))->toBe(PermissionRegistry::count())
        ->and($codes)->toHaveCount(PermissionRegistry::count())
        ->and($response->json('data.guard'))->toBe(PermissionRegistry::GUARD);
});

it('offers no stale permission as a configurable choice', function (): void {
    // permissions:sync preserves rows it no longer recognizes (D-036). They are
    // not assignable: the resolver would refuse them, so a grant built on one
    // would look configured and do nothing.
    $admin = matrixAdministrator();
    Permission::create(['name' => 'legacy.unmanaged.capability', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $response = $this->actingAs($admin)->getJson('/api/v1/permissions')->assertOk();

    expect($response->getContent())->not->toContain('legacy.unmanaged.capability');
});

it('never offers TEAM as an assignable scope', function (): void {
    $response = $this->actingAs(matrixAdministrator())->getJson('/api/v1/permissions')->assertOk();

    $scopes = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => array_column($group['permissions'], 'allowed_scopes'))
        ->flatten()
        ->unique()
        ->values()
        ->all();

    expect($scopes)->not->toContain('TEAM')
        ->and($scopes)->toContain('OWN', 'ASSIGNED', 'OFFICE', 'ALL');
});

it('marks a registered but unimplemented permission as deferred', function (): void {
    $response = $this->actingAs(matrixAdministrator())->getJson('/api/v1/permissions')->assertOk();

    $entry = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->firstWhere('code', 'security.settings.view');

    // The Company lifecycle codes joined at M2.2 and left at M2.3, which is the
    // flag working rather than the list churning: M2.2 put "Clients & Parties"
    // in the navigation without shipping Companies, and M2.3 shipped them.
    // Relationships stay deferred, and more sharply than before — Companies is a
    // live surface now, so granting `companies.management.view` and getting no
    // directors section is exactly the surprise the badge prevents (D-083).
    $expected = [
        'security.settings.view',
        'security.settings.manage',
        'companies.management.view',
        'companies.management.update',
        'companies.shareholders.view',
        'companies.shareholders.update',
    ];

    expect($entry['deferred'])->toBeTrue()
        ->and($response->json('meta.deferred'))->toBe($expected);

    // Everything else is actionable, or at least not flagged otherwise.
    $deferredCodes = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->where('deferred', true)
        ->pluck('code')
        ->all();

    sort($deferredCodes);
    $sortedExpected = $expected;
    sort($sortedExpected);

    expect($deferredCodes)->toBe($sortedExpected);
});

it('no longer defers a permission whose flow has since been built', function (): void {
    // `users.reset_password` carried the deferred badge from M1.5 until M1.9
    // built the flow (O-028). A badge that outlives its reason trains people to
    // ignore the badge, so M1.10 removes it — and this test keeps it removed.
    $response = $this->actingAs(matrixAdministrator())->getJson('/api/v1/permissions')->assertOk();

    $entry = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->firstWhere('code', 'users.reset_password');

    expect($entry['deferred'])->toBeFalse()
        ->and($response->json('meta.deferred'))->not->toContain('users.reset_password');
});

it('defers only permissions that genuinely have no endpoint', function (): void {
    // The list is checked against the router rather than trusted: a deferred
    // code with a live route would understate what an administrator is granting.
    $deferred = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'security/settings'));

    expect($deferred)->toBeEmpty();

    // And the converse: every implemented security capability is NOT deferred.
    $response = $this->actingAs(matrixAdministrator())->getJson('/api/v1/permissions')->assertOk();

    $flags = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->keyBy('code');

    foreach (['security.sessions.view', 'security.sessions.revoke', 'security.mfa.manage'] as $code) {
        expect($flags[$code]['deferred'])->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| Scope rules
|--------------------------------------------------------------------------
*/

it('allows only ALL for deployment-global authorization permissions', function (string $permission): void {
    expect(app(PermissionScopeRules::class)->allowedFor($permission))->toBe([DataScope::ALL]);
})->with([
    'roles.view', 'roles.create', 'roles.update', 'roles.delete',
    'permissions.view', 'permissions.assign',
]);

it('allows OWN, OFFICE and ALL for reading users', function (): void {
    expect(app(PermissionScopeRules::class)->allowedFor('users.view'))
        ->toBe([DataScope::OWN, DataScope::OFFICE, DataScope::ALL]);
});

it('allows only OFFICE and ALL for administering users', function (string $permission): void {
    // OWN is not an administrative predicate (D-049).
    expect(app(PermissionScopeRules::class)->allowedFor($permission))
        ->toBe([DataScope::OFFICE, DataScope::ALL]);
})->with(['users.create', 'users.update', 'users.disable', 'users.reset_password']);

it('allows the generic non-TEAM set for a permission whose domain is not built yet', function (string $permission): void {
    expect(app(PermissionScopeRules::class)->allowedFor($permission))
        ->toBe([DataScope::OWN, DataScope::ASSIGNED, DataScope::OFFICE, DataScope::ALL]);
})->with(['notary.deeds.approve', 'ppat.warkah.verify', 'projects.view', 'documents.upload']);

it('never allows TEAM for any canonical permission', function (): void {
    $rules = app(PermissionScopeRules::class);

    foreach (PermissionRegistry::all() as $permission) {
        expect($rules->permits($permission, DataScope::TEAM))->toBeFalse();
    }
});

it('introduces no scope ranking', function (): void {
    $forbidden = ['widest', 'max', 'rank', 'level', 'weight', 'priority', 'higherthan', 'compare', 'strongest'];

    $methods = array_map(
        fn (ReflectionMethod $m): string => strtolower($m->getName()),
        (new ReflectionClass(PermissionScopeRules::class))->getMethods(),
    );

    foreach ($forbidden as $name) {
        expect($methods)->not->toContain($name);
    }
});

/*
|--------------------------------------------------------------------------
| Reading a role's configuration
|--------------------------------------------------------------------------
*/

it('returns a role\'s configured grants with their exact scopes', function (): void {
    $admin = matrixAdministrator();
    $role = makeRole('CONFIGURED_ROLE');

    $permission = Permission::findByName('projects.view');
    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OFFICE);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/roles/{$role->getKey()}/permissions")
        ->assertOk();

    expect($response->json('data.permissions'))->toBe([['code' => 'projects.view', 'scope' => 'OFFICE']])
        ->and($response->json('data.malformed'))->toBe([])
        ->and($response->json('meta.total'))->toBe(1);
});

it('reports a grant with no scope metadata as malformed rather than assuming ALL', function (): void {
    // The resolver ignores such a grant (D-039). Reading it as ALL would be a
    // privilege escalation invented by the reporting layer.
    $admin = matrixAdministrator();
    $role = makeRole('LEGACY_ROLE');
    $role->givePermissionTo(Permission::findByName('projects.view'));

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/roles/{$role->getKey()}/permissions")
        ->assertOk();

    expect($response->json('data.permissions'))->toBe([])
        ->and($response->json('data.malformed'))->toBe([
            ['code' => 'projects.view', 'scope' => null, 'reason' => 'MISSING_SCOPE'],
        ]);
});

it('reports a scope row whose grant is gone as malformed', function (): void {
    $admin = matrixAdministrator();
    $role = makeRole('LEGACY_ROLE');
    $permission = Permission::findByName('projects.view');

    grantScope($role, $permission, DataScope::OWN);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/roles/{$role->getKey()}/permissions")
        ->assertOk();

    expect($response->json('data.malformed'))->toBe([
        ['code' => 'projects.view', 'scope' => 'OWN', 'reason' => 'SCOPE_WITHOUT_GRANT'],
    ]);
});

it('reports a legacy TEAM grant without treating it as assignable', function (): void {
    // TEAM stays representable, never assignable, and is never reinterpreted as
    // OFFICE (D-042).
    $admin = matrixAdministrator();
    $role = makeRole('LEGACY_TEAM_ROLE');
    $permission = Permission::findByName('projects.view');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::TEAM);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/roles/{$role->getKey()}/permissions")
        ->assertOk();

    expect($response->json('data.permissions'))->toBe([['code' => 'projects.view', 'scope' => 'TEAM']]);

    // And it cannot be saved back.
    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'projects.view', 'scope' => 'TEAM']],
    ])->assertStatus(422);
});

it('forbids reading a role\'s configuration without permissions.view at ALL', function (): void {
    $user = User::factory()->create();
    grantPermissionScope($user, 'permissions.view', DataScope::OFFICE);
    grantPermissionScope($user, 'roles.view', DataScope::ALL);

    $role = makeRole('SOME_ROLE');

    $this->actingAs($user)->getJson("/api/v1/roles/{$role->getKey()}/permissions")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Writing a role's configuration
|--------------------------------------------------------------------------
*/

it('saves a grant and its scope together', function (): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [
            ['code' => 'projects.view', 'scope' => 'OFFICE'],
            ['code' => 'projects.create', 'scope' => 'OWN'],
        ],
    ])->assertOk();

    expect($role->fresh()->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['projects.create', 'projects.view'])
        ->and(RolePermissionScope::query()->where('role_id', $role->getKey())->count())->toBe(2);

    $scopes = DB::table('role_permission_scopes')
        ->join('permissions as p', 'p.id', '=', 'role_permission_scopes.permission_id')
        ->where('role_id', $role->getKey())
        ->pluck('role_permission_scopes.scope', 'p.name');

    expect($scopes['projects.view'])->toBe('OFFICE')
        ->and($scopes['projects.create'])->toBe('OWN');
});

it('never leaves a grant without its scope row', function (): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => collect(PermissionRegistry::all())->take(20)
            ->map(fn (string $code): array => ['code' => $code, 'scope' => 'ALL'])->values()->all(),
    ])->assertOk();

    $grants = DB::table('role_has_permissions')->where('role_id', $role->getKey())->count();
    $scopes = RolePermissionScope::query()->where('role_id', $role->getKey())->count();

    expect($grants)->toBe(20)->and($scopes)->toBe(20);
});

it('removes both rows when a grant is dropped', function (): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [
            ['code' => 'projects.view', 'scope' => 'OFFICE'],
            ['code' => 'projects.create', 'scope' => 'OFFICE'],
        ],
    ])->assertOk();

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'projects.view', 'scope' => 'OFFICE']],
    ])->assertOk();

    expect($role->fresh()->permissions()->pluck('name')->all())->toBe(['projects.view'])
        ->and(RolePermissionScope::query()->where('role_id', $role->getKey())->count())->toBe(1);
});

it('keeps exactly one grant and one scope row when a scope changes', function (): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    foreach (['OWN', 'OFFICE', 'ALL'] as $scope) {
        $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
            'permissions' => [['code' => 'projects.view', 'scope' => $scope]],
        ])->assertOk();
    }

    expect(DB::table('role_has_permissions')->where('role_id', $role->getKey())->count())->toBe(1)
        ->and(RolePermissionScope::query()->where('role_id', $role->getKey())->count())->toBe(1)
        ->and(RolePermissionScope::query()->where('role_id', $role->getKey())->value('scope'))
        ->toBe(DataScope::ALL);
});

it('empties a role when sent an empty configuration', function (): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'projects.view', 'scope' => 'OFFICE']],
    ])->assertOk();

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [],
    ])->assertOk();

    expect($role->fresh()->permissions()->count())->toBe(0)
        ->and(RolePermissionScope::query()->where('role_id', $role->getKey())->count())->toBe(0);
});

it('makes a saved grant immediately effective for its holders', function (): void {
    // Proves Spatie's cache was invalidated: a stale collection would leave the
    // new grant invisible to the resolver.
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $member = User::factory()->create();
    $member->assignRole($role);

    expect(resolveAccess($member->fresh(), 'projects.view')->granted)->toBeFalse();

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'projects.view', 'scope' => 'OFFICE']],
    ])->assertOk();

    expect(resolveAccess($member->fresh(), 'projects.view')->scopeValues())->toBe(['OFFICE']);
});

it('rejects a permission the registry does not declare', function (string $code): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => $code, 'scope' => 'ALL']],
    ])->assertStatus(422)->assertJsonValidationErrors('permissions.0.code');
})->with([
    'invented' => 'projects.obliterate',
    'forbidden by section 21' => 'audit.update',
    'superseded alias' => 'documents.view_sensitive',
]);

it('rejects a stale database permission even though the row exists', function (): void {
    $admin = matrixAdministrator();
    Permission::create(['name' => 'legacy.unmanaged.capability', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'legacy.unmanaged.capability', 'scope' => 'ALL']],
    ])->assertStatus(422)->assertJsonValidationErrors('permissions.0.code');
});

it('rejects a permission that exists only on another guard', function (): void {
    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    $admin = matrixAdministrator();
    DB::table('permissions')->insert([
        'name' => 'other.guard.capability', 'guard_name' => 'api',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'other.guard.capability', 'scope' => 'ALL']],
    ])->assertStatus(422)->assertJsonValidationErrors('permissions.0.code');
});

it('rejects a duplicated permission code', function (): void {
    // Ambiguous rather than last-wins: guessing which the administrator meant
    // is how a saved configuration stops matching the screen that produced it.
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [
            ['code' => 'projects.view', 'scope' => 'OWN'],
            ['code' => 'projects.view', 'scope' => 'ALL'],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('permissions.1.code');
});

it('rejects a scope the permission does not allow', function (string $code, string $scope): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => $code, 'scope' => $scope]],
    ])->assertStatus(422)->assertJsonValidationErrors('permissions.0.scope');
})->with([
    'roles.view at OFFICE' => ['roles.view', 'OFFICE'],
    'permissions.assign at OWN' => ['permissions.assign', 'OWN'],
    'users.create at OWN' => ['users.create', 'OWN'],
    'users.disable at ASSIGNED' => ['users.disable', 'ASSIGNED'],
    'projects.view at TEAM' => ['projects.view', 'TEAM'],
    'unknown scope' => ['projects.view', 'EVERYTHING'],
]);

it('changes nothing when a request is rejected', function (): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'projects.view', 'scope' => 'OFFICE']],
    ])->assertOk();

    // One valid grant followed by an invalid one: nothing may be applied.
    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [
            ['code' => 'projects.create', 'scope' => 'OFFICE'],
            ['code' => 'projects.obliterate', 'scope' => 'ALL'],
        ],
    ])->assertStatus(422);

    expect($role->fresh()->permissions()->pluck('name')->all())->toBe(['projects.view'])
        ->and(RolePermissionScope::query()->where('role_id', $role->getKey())->count())->toBe(1);
});

it('forbids saving without permissions.assign at ALL', function (DataScope $scope): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    grantPermissionScope($user, 'permissions.assign', $scope);
    grantPermissionScope($user, 'permissions.view', DataScope::ALL);

    $role = makeRole('TARGET_ROLE');

    $this->actingAs($user)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'projects.view', 'scope' => 'OFFICE']],
    ])->assertForbidden();
})->with([
    'OFFICE' => DataScope::OFFICE,
    'OWN' => DataScope::OWN,
    'TEAM' => DataScope::TEAM,
    'ASSIGNED' => DataScope::ASSIGNED,
]);

it('forbids saving while an active DENY override stands', function (): void {
    $admin = matrixAdministrator();
    makeOverride($admin, Permission::findByName('permissions.assign'), UserPermissionEffect::DENY);

    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [],
    ])->assertForbidden();
});

it('allows saving through an ALLOW override carrying ALL', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    // Another administrator must exist, or the continuity guard would refuse.
    grantPermissionScope(User::factory()->create(), 'permissions.assign', DataScope::ALL);

    makeOverride($user, Permission::findByName('permissions.assign'), UserPermissionEffect::ALLOW, DataScope::ALL);
    makeOverride($user, Permission::findByName('permissions.view'), UserPermissionEffect::ALLOW, DataScope::ALL);

    $role = makeRole('TARGET_ROLE');

    $this->actingAs($user)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'projects.view', 'scope' => 'OFFICE']],
    ])->assertOk();
});

it('forbids saving from a direct package permission', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    $user->givePermissionTo(Permission::findByName('permissions.assign'));

    $role = makeRole('TARGET_ROLE');

    expect($user->hasDirectPermission('permissions.assign'))->toBeTrue();

    $this->actingAs($user)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [],
    ])->assertForbidden();
});

it('gives the SUPER_ADMIN role name no privilege in the matrix', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    $user->assignRole(makeRole('SUPER_ADMIN'));

    $role = makeRole('TARGET_ROLE');

    $this->actingAs($user)->getJson('/api/v1/permissions')->assertForbidden();
    $this->actingAs($user)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [],
    ])->assertForbidden();
});

it('touches no direct package permissions', function (): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $before = DB::table('model_has_permissions')->count();

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'projects.view', 'scope' => 'OFFICE']],
    ])->assertOk();

    expect(DB::table('model_has_permissions')->count())->toBe($before);
});

it('leaves other roles alone', function (): void {
    $admin = matrixAdministrator();

    $other = makeRole('OTHER_ROLE');
    $permission = Permission::findByName('tasks.view');
    $other->givePermissionTo($permission);
    grantScope($other, $permission, DataScope::OWN);

    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'projects.view', 'scope' => 'OFFICE']],
    ])->assertOk();

    expect($other->fresh()->permissions()->pluck('name')->all())->toBe(['tasks.view'])
        ->and(RolePermissionScope::query()->where('role_id', $other->getKey())->value('scope'))
        ->toBe(DataScope::OWN);
});

it('does not let the matrix detach a role to work around the delete guard', function (): void {
    // M1.4 refuses to delete an assigned role; emptying its permissions must
    // not become a way to make that refusal moot.
    $admin = matrixAdministrator();
    grantPermissionScope($admin, 'roles.delete', DataScope::ALL);

    $role = makeRole('ASSIGNED_ROLE');
    User::factory()->create()->assignRole($role);

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [],
    ])->assertOk();

    $this->actingAs($admin)->deleteJson("/api/v1/roles/{$role->getKey()}")->assertStatus(409);

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeTrue();
});

it('rejects a malformed payload shape', function (mixed $payload): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)
        ->putJson("/api/v1/roles/{$role->getKey()}/permissions", $payload)
        ->assertStatus(422);
})->with([
    'missing key' => [[]],
    'not an array' => [['permissions' => 'projects.view']],
    'missing scope' => [['permissions' => [['code' => 'projects.view']]]],
    'missing code' => [['permissions' => [['scope' => 'ALL']]]],
]);

it('leaves the created scope rows keyed by ULID', function (): void {
    $admin = matrixAdministrator();
    $role = makeRole('TARGET_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [['code' => 'projects.view', 'scope' => 'OFFICE']],
    ])->assertOk();

    $id = RolePermissionScope::query()->where('role_id', $role->getKey())->value('id');

    expect(strlen($id))->toBe(26)->and(Str::isUlid($id))->toBeTrue();
});
