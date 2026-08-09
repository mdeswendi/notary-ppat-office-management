<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Gives a user one canonical role-management permission at one Data Scope,
 * through a role whose name is deliberately unremarkable.
 */
function userWithRoleScope(string $permissionName, DataScope $scope, string $roleName = 'TEST_ROLE_ADMIN'): User
{
    $user = User::factory()->create();
    $permission = makePermission($permissionName);
    $role = makeRole($roleName);

    $role->givePermissionTo($permission);
    grantScope($role, $permission, $scope);
    $user->assignRole($role);

    return $user;
}

/**
 * The five endpoints, each with the permission that guards it and a request
 * that would succeed if authorization allowed it.
 *
 * @return array<string, array{0: string, 1: callable(User, ?Role): TestResponse}>
 */
function roleEndpoints(): array
{
    return [
        'index' => ['roles.view', fn (User $u, ?Role $r) => test()->actingAs($u)->getJson('/api/v1/roles')],
        'show' => ['roles.view', fn (User $u, ?Role $r) => test()->actingAs($u)->getJson("/api/v1/roles/{$r->getKey()}")],
        'store' => ['roles.create', fn (User $u, ?Role $r) => test()->actingAs($u)->postJson('/api/v1/roles', ['name' => 'BRAND_NEW_ROLE'])],
        'update' => ['roles.update', fn (User $u, ?Role $r) => test()->actingAs($u)->patchJson("/api/v1/roles/{$r->getKey()}", ['name' => 'RENAMED'])],
        'destroy' => ['roles.delete', fn (User $u, ?Role $r) => test()->actingAs($u)->deleteJson("/api/v1/roles/{$r->getKey()}")],
    ];
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

it('rejects an unauthenticated request to every role endpoint', function (): void {
    $target = makeRole('EXISTING_ROLE');

    expect($this->getJson('/api/v1/roles')->status())->toBe(401)
        ->and($this->getJson("/api/v1/roles/{$target->getKey()}")->status())->toBe(401)
        ->and($this->postJson('/api/v1/roles', ['name' => 'X'])->status())->toBe(401)
        ->and($this->patchJson("/api/v1/roles/{$target->getKey()}", ['name' => 'X'])->status())->toBe(401)
        ->and($this->deleteJson("/api/v1/roles/{$target->getKey()}")->status())->toBe(401);
});

it('forbids an authenticated user holding no permission at all', function (string $endpoint): void {
    [, $call] = roleEndpoints()[$endpoint];

    $call(User::factory()->create(), makeRole('EXISTING_ROLE'))->assertForbidden();
})->with(['index', 'show', 'store', 'update', 'destroy']);

/*
|--------------------------------------------------------------------------
| The ALL Data Scope is required for deployment-global records
|--------------------------------------------------------------------------
*/

it('allows the operation when the permission is held at ALL', function (string $endpoint): void {
    [$permission, $call] = roleEndpoints()[$endpoint];

    $user = userWithRoleScope($permission, DataScope::ALL);

    expect($call($user, makeRole('EXISTING_ROLE'))->status())->not->toBe(403);
})->with(['index', 'show', 'store', 'update', 'destroy']);

it('forbids the operation when the permission is held only at OFFICE', function (string $endpoint): void {
    // A Role definition has no office_id to match, so an office-scoped grant
    // has nothing to reach it with.
    [$permission, $call] = roleEndpoints()[$endpoint];

    $call(userWithRoleScope($permission, DataScope::OFFICE), makeRole('EXISTING_ROLE'))->assertForbidden();
})->with(['index', 'show', 'store', 'update', 'destroy']);

it('forbids the operation when the permission is held only at OWN', function (string $endpoint): void {
    [$permission, $call] = roleEndpoints()[$endpoint];

    $call(userWithRoleScope($permission, DataScope::OWN), makeRole('EXISTING_ROLE'))->assertForbidden();
})->with(['index', 'show', 'store', 'update', 'destroy']);

it('forbids the operation when the permission is held only at ASSIGNED', function (string $endpoint): void {
    [$permission, $call] = roleEndpoints()[$endpoint];

    $call(userWithRoleScope($permission, DataScope::ASSIGNED), makeRole('EXISTING_ROLE'))->assertForbidden();
})->with(['index', 'show', 'store', 'update', 'destroy']);

it('forbids the operation when the permission is held only at TEAM', function (string $endpoint): void {
    [$permission, $call] = roleEndpoints()[$endpoint];

    $call(userWithRoleScope($permission, DataScope::TEAM), makeRole('EXISTING_ROLE'))->assertForbidden();
})->with(['index', 'show', 'store', 'update', 'destroy']);

it('allows the operation when ALL arrives alongside a narrower scope', function (): void {
    // Presence, not precedence: the union contains ALL, so the user holds it.
    $user = User::factory()->create();
    $permission = makePermission('roles.view');

    foreach ([DataScope::OFFICE, DataScope::ALL] as $index => $scope) {
        $role = makeRole('TEST_ROLE_'.$index);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, $scope);
        $user->assignRole($role);
    }

    $this->actingAs($user)->getJson('/api/v1/roles')->assertOk();
});

it('forbids a role grant that carries no Data Scope at all', function (): void {
    $user = User::factory()->create();
    $permission = makePermission('roles.view');
    $role = makeRole('TEST_ROLE_ADMIN');

    $role->givePermissionTo($permission);
    $user->assignRole($role);

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
});

it('forbids the wrong permission even when held at ALL', function (): void {
    // roles.view does not imply roles.create.
    $user = userWithRoleScope('roles.view', DataScope::ALL);

    $this->actingAs($user)->getJson('/api/v1/roles')->assertOk();
    $this->actingAs($user)->postJson('/api/v1/roles', ['name' => 'NEW_ROLE'])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Overrides
|--------------------------------------------------------------------------
*/

it('forbids access while an active DENY override stands', function (): void {
    $user = userWithRoleScope('roles.view', DataScope::ALL);
    $permission = Permission::findByName('roles.view');

    makeOverride($user, $permission, UserPermissionEffect::DENY);

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
});

it('allows access through an ALLOW override carrying ALL', function (): void {
    $user = User::factory()->create();
    $permission = makePermission('roles.view');

    makeOverride($user, $permission, UserPermissionEffect::ALLOW, DataScope::ALL);

    $this->actingAs($user)->getJson('/api/v1/roles')->assertOk();
});

it('forbids access through an ALLOW override carrying only OFFICE', function (): void {
    // The override replaces the role result outright, so a narrower override
    // takes global access away rather than adding to it.
    $user = userWithRoleScope('roles.view', DataScope::ALL);
    $permission = Permission::findByName('roles.view');

    makeOverride($user, $permission, UserPermissionEffect::ALLOW, DataScope::OFFICE);

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
});

it('ignores an expired DENY override', function (): void {
    $user = userWithRoleScope('roles.view', DataScope::ALL);
    $permission = Permission::findByName('roles.view');

    makeOverride($user, $permission, UserPermissionEffect::DENY, expiresAt: now()->subMinute());

    $this->actingAs($user)->getJson('/api/v1/roles')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Paths that must not grant access
|--------------------------------------------------------------------------
*/

it('forbids a permission attached directly to the user through the package', function (string $endpoint): void {
    // Spatie honours the direct grant and its own Gate::before would answer
    // `can('roles.view')` with true. First-party authorization does not use
    // that path (D-029, D-041), so the endpoint still refuses.
    [$permission, $call] = roleEndpoints()[$endpoint];

    $user = User::factory()->create();
    $user->givePermissionTo(makePermission($permission));

    expect($user->hasDirectPermission($permission))->toBeTrue()
        ->and($user->can($permission))->toBeTrue();

    $call($user, makeRole('EXISTING_ROLE'))->assertForbidden();
})->with(['index', 'show', 'store', 'update', 'destroy']);

it('gives a role named SUPER_ADMIN no privilege of its own', function (): void {
    $user = User::factory()->create();
    $user->assignRole(makeRole('SUPER_ADMIN'));

    expect($user->hasRole('SUPER_ADMIN'))->toBeTrue();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
});

it('gives every documented default role name no privilege of its own', function (string $roleName): void {
    // The nine names are a default configuration, not authorization logic.
    $user = User::factory()->create();
    $user->assignRole(makeRole($roleName));

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
})->with([
    'SUPER_ADMIN', 'PRINCIPAL', 'OFFICE_MANAGER', 'NOTARY_STAFF', 'PPAT_STAFF',
    'FRONT_OFFICE', 'FINANCE', 'ARCHIVE_STAFF', 'AUDITOR',
]);

it('allows any role name once it holds the permission at ALL', function (string $roleName): void {
    $user = userWithRoleScope('roles.view', DataScope::ALL, $roleName);

    $this->actingAs($user)->getJson('/api/v1/roles')->assertOk();
})->with(['SUPER_ADMIN', 'ARCHIVE_STAFF', 'Notaris Pengganti', 'a role with spaces']);

it('registers no Gate::before or Gate::after bypass of its own', function (): void {
    // Spatie installs one Gate::before; the application adds none. Anything
    // beyond the package's single callback would need explaining.
    $gate = new ReflectionClass(app(Gate::class));

    $before = $gate->getProperty('beforeCallbacks');
    $after = $gate->getProperty('afterCallbacks');

    expect($before->getValue(app(Gate::class)))->toHaveCount(1)
        ->and($after->getValue(app(Gate::class)))->toBe([]);
});

it('never compares a role name anywhere in the authorization path', function (): void {
    $sources = [
        app_path('Policies/RolePolicy.php'),
        app_path('Domains/Authorization/EffectiveAccessResolver.php'),
        app_path('Http/Controllers/Api/V1/RoleController.php'),
        app_path('Domains/Authorization/Actions/CreateRole.php'),
        app_path('Domains/Authorization/Actions/RenameRole.php'),
        app_path('Domains/Authorization/Actions/DeleteRole.php'),
    ];

    foreach ($sources as $path) {
        // Strip block comments and line comments: the files discuss role names
        // at length, and prose is not behaviour.
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($path));

        expect($code)->not->toContain('hasRole')
            ->and($code)->not->toContain('SUPER_ADMIN')
            ->and($code)->not->toContain('Gate::before')
            ->and($code)->not->toContain('Gate::after');
    }
});

it('leaves authorization metadata untouched while answering a request', function (): void {
    $user = userWithRoleScope('roles.view', DataScope::ALL);

    $tables = ['permissions', 'roles', 'role_has_permissions', 'model_has_roles',
        'model_has_permissions', 'role_permission_scopes', 'user_permission_overrides'];

    $before = collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()]);

    $this->actingAs($user)->getJson('/api/v1/roles')->assertOk();

    expect(collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all())
        ->toBe($before->all());
});
