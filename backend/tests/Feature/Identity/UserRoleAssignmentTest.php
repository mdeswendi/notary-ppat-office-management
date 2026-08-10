<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Authorization\SyncCanonicalPermissions;
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
 * An authorization administrator, plus the canonical permission rows.
 */
function assignmentAdministrator(): User
{
    $user = User::factory()->create();

    app(SyncCanonicalPermissions::class)->handle();

    grantPermissionScope($user, 'permissions.view', DataScope::ALL);
    grantPermissionScope($user, 'permissions.assign', DataScope::ALL);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('rejects unauthenticated role membership requests', function (): void {
    $target = User::factory()->create();

    $this->getJson("/api/v1/users/{$target->getKey()}/roles")->assertUnauthorized();
    $this->putJson("/api/v1/users/{$target->getKey()}/roles", ['role_ids' => []])->assertUnauthorized();
});

it('requires permissions.view at ALL to read membership', function (DataScope $scope): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    grantPermissionScope($user, 'permissions.view', $scope);

    $target = User::factory()->create();

    $this->actingAs($user)->getJson("/api/v1/users/{$target->getKey()}/roles")->assertForbidden();
})->with([
    'OFFICE' => DataScope::OFFICE,
    'OWN' => DataScope::OWN,
    'ASSIGNED' => DataScope::ASSIGNED,
    'TEAM' => DataScope::TEAM,
]);

it('requires permissions.assign at ALL to change membership', function (DataScope $scope): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    grantPermissionScope($user, 'permissions.assign', $scope);
    grantPermissionScope($user, 'permissions.view', DataScope::ALL);

    $target = User::factory()->create();

    $this->actingAs($user)
        ->putJson("/api/v1/users/{$target->getKey()}/roles", ['role_ids' => []])
        ->assertForbidden();
})->with([
    'OFFICE' => DataScope::OFFICE,
    'OWN' => DataScope::OWN,
    'ASSIGNED' => DataScope::ASSIGNED,
    'TEAM' => DataScope::TEAM,
]);

it('does not accept users.update as authority for role assignment', function (): void {
    // Granting a role changes what somebody can do; correcting their phone
    // number does not. They are different capabilities (D-055).
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    foreach (['users.view', 'users.create', 'users.update', 'users.disable'] as $permission) {
        grantPermissionScope($user, $permission, DataScope::ALL);
    }

    $target = User::factory()->create();

    $this->actingAs($user)->getJson("/api/v1/users/{$target->getKey()}/roles")->assertForbidden();
    $this->actingAs($user)
        ->putJson("/api/v1/users/{$target->getKey()}/roles", ['role_ids' => []])
        ->assertForbidden();
});

it('forbids membership changes from a direct package permission', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    $user->givePermissionTo(Permission::findByName('permissions.assign'));

    $target = User::factory()->create();

    $this->actingAs($user)
        ->putJson("/api/v1/users/{$target->getKey()}/roles", ['role_ids' => []])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Reading and replacing membership
|--------------------------------------------------------------------------
*/

it('reads a user\'s roles', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create();

    $alpha = makeRole('ALPHA_ROLE');
    $beta = makeRole('BETA_ROLE');
    $target->assignRole($alpha, $beta);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/users/{$target->getKey()}/roles")
        ->assertOk();

    expect(collect($response->json('data.roles'))->pluck('name')->all())->toBe(['ALPHA_ROLE', 'BETA_ROLE'])
        ->and($response->json('meta.total'))->toBe(2)
        ->and($response->json('data.user.id'))->toBe($target->getKey());
});

it('gives a user several roles', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create();

    $alpha = makeRole('ALPHA_ROLE');
    $beta = makeRole('BETA_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/users/{$target->getKey()}/roles", [
        'role_ids' => [$alpha->getKey(), $beta->getKey()],
    ])->assertOk();

    expect($target->fresh()->getRoleNames()->sort()->values()->all())->toBe(['ALPHA_ROLE', 'BETA_ROLE']);
});

it('replaces membership wholesale', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create();

    $alpha = makeRole('ALPHA_ROLE');
    $beta = makeRole('BETA_ROLE');
    $target->assignRole($alpha);

    $this->actingAs($admin)->putJson("/api/v1/users/{$target->getKey()}/roles", [
        'role_ids' => [$beta->getKey()],
    ])->assertOk();

    expect($target->fresh()->getRoleNames()->all())->toBe(['BETA_ROLE']);
});

it('removes every role when sent an empty list', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create();
    $target->assignRole(makeRole('ALPHA_ROLE'));

    $this->actingAs($admin)
        ->putJson("/api/v1/users/{$target->getKey()}/roles", ['role_ids' => []])
        ->assertOk();

    expect($target->fresh()->getRoleNames()->all())->toBe([]);
});

it('rejects a duplicated role id', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create();
    $role = makeRole('ALPHA_ROLE');

    $this->actingAs($admin)->putJson("/api/v1/users/{$target->getKey()}/roles", [
        'role_ids' => [$role->getKey(), $role->getKey()],
    ])->assertStatus(422)->assertJsonValidationErrors('role_ids.0');
});

it('rejects a role that does not exist', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create();

    $this->actingAs($admin)->putJson("/api/v1/users/{$target->getKey()}/roles", [
        'role_ids' => [999999],
    ])->assertStatus(422)->assertJsonValidationErrors('role_ids.0');
});

it('rejects a role from another guard', function (): void {
    // A role on another guard could never grant anything the resolver honours,
    // so assigning one would look like access and deliver none.
    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    $admin = assignmentAdministrator();
    $target = User::factory()->create();

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'API_ROLE', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($admin)->putJson("/api/v1/users/{$target->getKey()}/roles", [
        'role_ids' => [$roleId],
    ])->assertStatus(422)->assertJsonValidationErrors('role_ids.0');
});

it('cannot reach a soft-deleted user', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create();
    $target->delete();

    $this->actingAs($admin)->getJson("/api/v1/users/{$target->getKey()}/roles")->assertNotFound();
    $this->actingAs($admin)
        ->putJson("/api/v1/users/{$target->getKey()}/roles", ['role_ids' => []])
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Effect on resolution, and what stays untouched
|--------------------------------------------------------------------------
*/

it('changes effective access as roles are granted and removed', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create();

    $office = makeRole('OFFICE_ROLE');
    $own = makeRole('OWN_ROLE');
    $permission = Permission::findByName('projects.view');

    foreach ([[$office, DataScope::OFFICE], [$own, DataScope::OWN]] as [$role, $scope]) {
        $role->givePermissionTo($permission);
        grantScope($role, $permission, $scope);
    }

    expect(resolveAccess($target->fresh(), 'projects.view')->granted)->toBeFalse();

    $this->actingAs($admin)->putJson("/api/v1/users/{$target->getKey()}/roles", [
        'role_ids' => [$office->getKey(), $own->getKey()],
    ])->assertOk();

    // Union across roles, in canonical order (D-028).
    expect(resolveAccess($target->fresh(), 'projects.view')->scopeValues())->toBe(['OWN', 'OFFICE']);

    $this->actingAs($admin)->putJson("/api/v1/users/{$target->getKey()}/roles", [
        'role_ids' => [$own->getKey()],
    ])->assertOk();

    expect(resolveAccess($target->fresh(), 'projects.view')->scopeValues())->toBe(['OWN']);
});

it('leaves role configuration, overrides and profile untouched', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create(['name' => 'Original Name', 'preferred_locale' => 'id']);

    $role = makeRole('ALPHA_ROLE');
    $permission = Permission::findByName('projects.view');
    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OFFICE);

    $target->givePermissionTo(Permission::findByName('tasks.view'));
    makeOverride($target, Permission::findByName('calendar.view'), UserPermissionEffect::DENY, createdBy: $admin);

    $rolePermissions = DB::table('role_has_permissions')->orderBy('permission_id')->get()->toArray();
    $scopes = DB::table('role_permission_scopes')->orderBy('id')->get()->toArray();
    $direct = DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray();
    $overrides = DB::table('user_permission_overrides')->orderBy('id')->get()->toArray();

    $this->actingAs($admin)->putJson("/api/v1/users/{$target->getKey()}/roles", [
        'role_ids' => [$role->getKey()],
    ])->assertOk();

    $target->refresh();

    expect(DB::table('role_has_permissions')->orderBy('permission_id')->get()->toArray())->toEqual($rolePermissions)
        ->and(DB::table('role_permission_scopes')->orderBy('id')->get()->toArray())->toEqual($scopes)
        ->and(DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray())->toEqual($direct)
        ->and(DB::table('user_permission_overrides')->orderBy('id')->get()->toArray())->toEqual($overrides)
        ->and($target->name)->toBe('Original Name')
        ->and($target->preferred_locale)->toBe('id')
        ->and($target->is_active)->toBeTrue();
});

it('exposes no direct permission assignment surface', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create();

    // A payload attempting direct grants is simply not part of the contract.
    $this->actingAs($admin)->putJson("/api/v1/users/{$target->getKey()}/roles", [
        'role_ids' => [],
        'permissions' => ['projects.view'],
    ])->assertOk();

    expect(DB::table('model_has_permissions')->where('model_id', $target->getKey())->count())->toBe(0);

    // And no route offers it.
    $this->actingAs($admin)
        ->putJson("/api/v1/users/{$target->getKey()}/permissions", ['permissions' => []])
        ->assertNotFound();
    $this->actingAs($admin)
        ->putJson("/api/v1/users/{$target->getKey()}/overrides", ['overrides' => []])
        ->assertNotFound();
});

it('returns the resulting membership', function (): void {
    $admin = assignmentAdministrator();
    $target = User::factory()->create();
    $role = makeRole('ALPHA_ROLE');

    $response = $this->actingAs($admin)->putJson("/api/v1/users/{$target->getKey()}/roles", [
        'role_ids' => [$role->getKey()],
    ])->assertOk();

    expect(collect($response->json('data.roles'))->pluck('name')->all())->toBe(['ALPHA_ROLE'])
        ->and($response->json('data.roles.0.id'))->toBe($role->getKey())
        ->and(Str::isUlid($response->json('data.user.id')))->toBeTrue();
});
