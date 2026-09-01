<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Deliberately non-production names. The real permission matrix in
 * docs/02_MENU_AND_PERMISSIONS.md is seeded in M1, not here, and nothing in
 * this file may be mistaken for it.
 */
const TEST_PERMISSION = 'test.foundation.view';
const TEST_OTHER_PERMISSION = 'test.foundation.manage';
const TEST_ROLE = 'TEST_FOUNDATION_ROLE';

beforeEach(function (): void {
    // Permissions created mid-test are invisible to the resolver until its
    // cache is dropped. This is the package's own mechanism, not a bespoke one.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('lets a ULID user hold a role', function (): void {
    $user = User::factory()->create();
    Role::create(['name' => TEST_ROLE]);

    $user->assignRole(TEST_ROLE);

    expect($user->hasRole(TEST_ROLE))->toBeTrue()
        ->and($user->getRoleNames()->all())->toBe([TEST_ROLE]);
});

it('lets a ULID user hold a direct permission', function (): void {
    $user = User::factory()->create();
    Permission::create(['name' => TEST_PERMISSION]);

    $user->givePermissionTo(TEST_PERMISSION);

    expect($user->hasDirectPermission(TEST_PERMISSION))->toBeTrue();
});

it('stores the complete user ULID in the role pivot', function (): void {
    $user = User::factory()->create();
    Role::create(['name' => TEST_ROLE]);
    $user->assignRole(TEST_ROLE);

    $modelId = DB::table('model_has_roles')->value('model_id');

    // char(26) pads on some drivers; the value must survive intact either way.
    expect(trim((string) $modelId))->toBe($user->id)
        ->and(strlen(trim((string) $modelId)))->toBe(26);

    expect(DB::table('model_has_roles')->value('model_type'))->toBe(User::class);
});

it('stores the complete user ULID in the permission pivot', function (): void {
    $user = User::factory()->create();
    Permission::create(['name' => TEST_PERMISSION]);
    $user->givePermissionTo(TEST_PERMISSION);

    $modelId = DB::table('model_has_permissions')->value('model_id');

    expect(trim((string) $modelId))->toBe($user->id)
        ->and(strlen(trim((string) $modelId)))->toBe(26);
});

it('grants a permission through a role in package storage', function (): void {
    $user = User::factory()->create();
    $permission = Permission::create(['name' => TEST_PERMISSION]);
    $role = Role::create(['name' => TEST_ROLE]);

    $role->givePermissionTo($permission);
    $user->assignRole($role);

    // Written as an M0.8 test asserting `$user->can(TEST_PERMISSION)` was true,
    // with a comment calling the Gate "what controllers and policies will use".
    // That expectation was withdrawn in M1.4A: the Gate answered from package
    // state alone, with no Data Scope, no override handling, and no registry
    // check, so it could allow what EffectiveAccessResolver refuses (D-048).
    //
    // The storage relationship it really tests is unchanged and still asserted;
    // only the reading of it moved.
    expect($role->hasPermissionTo(TEST_PERMISSION))->toBeTrue()
        ->and($user->hasPermissionTo(TEST_PERMISSION))->toBeTrue()
        ->and($user->hasDirectPermission(TEST_PERMISSION))->toBeFalse()
        ->and($user->getPermissionsViaRoles()->pluck('name')->all())->toBe([TEST_PERMISSION]);
});

it('does not answer a canonical permission name through the Gate', function (): void {
    // The M1.4A boundary, stated where M0.8 stated the opposite. Nothing is
    // authorized by naming a permission to the Gate any more; permission checks
    // go through a Policy that delegates to EffectiveAccessResolver.
    $user = User::factory()->create();
    $permission = Permission::create(['name' => TEST_PERMISSION]);
    $role = Role::create(['name' => TEST_ROLE]);

    $role->givePermissionTo($permission);
    $user->assignRole($role);

    expect($user->can(TEST_PERMISSION))->toBeFalse()
        ->and(Gate::forUser($user)->allows(TEST_PERMISSION))->toBeFalse();
});

it('denies a permission the user was never granted', function (): void {
    $user = User::factory()->create();
    Permission::create(['name' => TEST_PERMISSION]);
    Permission::create(['name' => TEST_OTHER_PERMISSION]);
    $role = Role::create(['name' => TEST_ROLE]);
    $role->givePermissionTo(TEST_PERMISSION);
    $user->assignRole($role);

    expect($user->hasPermissionTo(TEST_OTHER_PERMISSION))->toBeFalse()
        ->and($user->can(TEST_OTHER_PERMISSION))->toBeFalse();
});

it('denies everything to a user with no role at all', function (): void {
    $user = User::factory()->create();
    Permission::create(['name' => TEST_PERMISSION]);

    expect($user->hasPermissionTo(TEST_PERMISSION))->toBeFalse()
        ->and($user->can(TEST_PERMISSION))->toBeFalse();
});

it('returns empty roles and permissions for a user with no assignments', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'preferred_locale' => $user->preferred_locale,
                'office' => [
                    'id' => $user->office->id,
                    'code' => $user->office->code,
                    'name' => $user->office->name,
                ],
                'roles' => [],
                'permissions' => [],
                'permission_scopes' => [],
            ],
        ]);
});

it('exposes role names and effectively granted permissions through the current user endpoint', function (): void {
    // Written at M0.8 asserting that a role grant alone produced a permission
    // here. M1.7 changed the field's meaning: it now reports what
    // EffectiveAccessResolver grants, and a grant with no Data Scope grants
    // nothing (D-039, D-062). The scope row is what the test was always
    // really about — the capability actually being usable.
    $user = User::factory()->create();
    $role = makeRole(TEST_ROLE);
    $permission = makePermission('projects.view');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OFFICE);
    $user->assignRole($role);

    $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    expect($response->json('data.roles'))->toBe([TEST_ROLE])
        ->and($response->json('data.permissions'))->toBe(['projects.view'])
        ->and($response->json('data.permission_scopes'))->toBe(['projects.view' => ['OFFICE']]);
});

it('omits a role grant that carries no Data Scope', function (): void {
    $user = User::factory()->create();
    $role = makeRole(TEST_ROLE);

    $role->givePermissionTo(makePermission('projects.view'));
    $user->assignRole($role);

    $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    expect($response->json('data.roles'))->toBe([TEST_ROLE])
        ->and($response->json('data.permissions'))->toBe([]);
});

it('excludes a permission attached directly through the package', function (): void {
    // The exact inverse of the M0.8 test this replaces, which asserted direct
    // grants appeared alongside inherited ones. That was O-026: the browser saw
    // capability the resolver refuses, because `getAllPermissions()` counts
    // direct grants that D-029 and D-041 exclude. `/me` now reports effective
    // access, so the two agree.
    $user = User::factory()->create();
    $role = makeRole(TEST_ROLE);
    $inherited = makePermission('projects.view');

    $role->givePermissionTo($inherited);
    grantScope($role, $inherited, DataScope::OWN);
    $user->assignRole($role);

    $user->givePermissionTo(makePermission('projects.create'));

    $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    expect($user->hasDirectPermission('projects.create'))->toBeTrue()
        ->and($response->json('data.permissions'))->toBe(['projects.view'])
        ->and($response->json('data.permission_scopes'))->toBe(['projects.view' => ['OWN']]);
});

it('lists a permission once and unions the scopes reaching it', function (): void {
    // Replaces an M0.8 de-duplication test that counted three paths including a
    // direct grant. Two roles now union their scopes (D-028); the direct grant
    // contributes nothing at all.
    $user = User::factory()->create();
    $permission = makePermission('projects.view');

    foreach ([DataScope::OWN, DataScope::OFFICE] as $index => $scope) {
        $role = makeRole(TEST_ROLE.'_'.$index);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, $scope);
        $user->assignRole($role);
    }

    $user->givePermissionTo($permission);

    $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    expect($response->json('data.permissions'))->toBe(['projects.view'])
        ->and($response->json('data.permission_scopes'))->toBe(['projects.view' => ['OWN', 'OFFICE']]);
});

it('never exposes authorization internals through the current user endpoint', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => TEST_ROLE]);
    $role->givePermissionTo(Permission::create(['name' => TEST_PERMISSION]));
    $user->assignRole($role);

    $body = $this->actingAs($user)->getJson('/api/v1/me')->getContent();

    expect($body)
        ->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain('guard_name')
        ->not->toContain('pivot')
        ->not->toContain('role_id')
        ->not->toContain('permission_id')
        ->not->toContain('model_type');
});

it('still rejects an anonymous request to the current user endpoint', function (): void {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
