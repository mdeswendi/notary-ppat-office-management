<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Models\RolePermissionScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * A user who may do everything M1.4 offers: all four role permissions, each at
 * the ALL scope a deployment-global record requires.
 */
function roleAdministrator(): User
{
    $user = User::factory()->create();
    $role = makeRole('TEST_ROLE_ADMINISTRATOR');

    foreach (['roles.view', 'roles.create', 'roles.update', 'roles.delete'] as $name) {
        $permission = makePermission($name);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, DataScope::ALL);
    }

    $user->assignRole($role);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Create
|--------------------------------------------------------------------------
*/

it('creates a role', function (): void {
    $response = $this->actingAs(roleAdministrator())
        ->postJson('/api/v1/roles', ['name' => 'NOTARY_REVIEWER']);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'NOTARY_REVIEWER')
        ->assertJsonPath('data.guard_name', 'web');

    expect(Role::query()->where('name', 'NOTARY_REVIEWER')->exists())->toBeTrue();
});

it('creates the role on the registry guard', function (): void {
    // Not `auth.defaults.guard`, which reads `sanctum` inside an authenticated
    // request — a role on that guard could never be granted anything (D-046).
    $this->actingAs(roleAdministrator())
        ->postJson('/api/v1/roles', ['name' => 'NOTARY_REVIEWER'])
        ->assertCreated();

    expect(Role::query()->where('name', 'NOTARY_REVIEWER')->value('guard_name'))
        ->toBe(PermissionRegistry::GUARD)
        ->toBe('web');
});

it('creates a role with no capability whatsoever', function (): void {
    $admin = roleAdministrator();

    $permissionsBefore = DB::table('role_has_permissions')->count();
    $scopesBefore = RolePermissionScope::query()->count();
    $membershipsBefore = DB::table('model_has_roles')->count();

    $this->actingAs($admin)->postJson('/api/v1/roles', ['name' => 'EMPTY_ROLE'])->assertCreated();

    $created = Role::query()->where('name', 'EMPTY_ROLE')->firstOrFail();

    expect($created->permissions()->count())->toBe(0)
        ->and(RolePermissionScope::query()->where('role_id', $created->getKey())->count())->toBe(0)
        ->and(DB::table('model_has_roles')->where('role_id', $created->getKey())->count())->toBe(0)
        // Nobody else's assignments moved either.
        ->and(DB::table('role_has_permissions')->count())->toBe($permissionsBefore)
        ->and(RolePermissionScope::query()->count())->toBe($scopesBefore)
        ->and(DB::table('model_has_roles')->count())->toBe($membershipsBefore);
});

it('rejects a duplicate role name', function (): void {
    $admin = roleAdministrator();
    makeRole('EXISTING_ROLE');

    $this->actingAs($admin)
        ->postJson('/api/v1/roles', ['name' => 'EXISTING_ROLE'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('rejects a blank or missing name', function (mixed $name): void {
    $this->actingAs(roleAdministrator())
        ->postJson('/api/v1/roles', ['name' => $name])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'null' => null,
]);

it('rejects a name longer than the column allows', function (): void {
    $this->actingAs(roleAdministrator())
        ->postJson('/api/v1/roles', ['name' => str_repeat('A', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('accepts a role name that is not upper snake case', function (): void {
    // The nine documented defaults are a configuration, not a naming rule. An
    // office may call a role whatever makes sense to it.
    $this->actingAs(roleAdministrator())
        ->postJson('/api/v1/roles', ['name' => 'Notaris Pengganti'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Notaris Pengganti');
});

it('stores the submitted name without rewriting it', function (): void {
    $this->actingAs(roleAdministrator())
        ->postJson('/api/v1/roles', ['name' => 'Staf Arsip'])
        ->assertCreated();

    expect(Role::query()->where('name', 'Staf Arsip')->exists())->toBeTrue();
});

it('ignores a guard supplied by the client on create', function (): void {
    $this->actingAs(roleAdministrator())
        ->postJson('/api/v1/roles', ['name' => 'INJECTED_GUARD_ROLE', 'guard_name' => 'api'])
        ->assertCreated();

    expect(Role::query()->where('name', 'INJECTED_GUARD_ROLE')->value('guard_name'))->toBe('web')
        ->and(Role::query()->where('guard_name', 'api')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Read
|--------------------------------------------------------------------------
*/

it('lists roles ordered by name with a total', function (): void {
    $admin = roleAdministrator();

    foreach (['ZEBRA_ROLE', 'ALPHA_ROLE', 'MIDDLE_ROLE'] as $name) {
        makeRole($name);
    }

    $response = $this->actingAs($admin)->getJson('/api/v1/roles')->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toBe(['ALPHA_ROLE', 'MIDDLE_ROLE', 'TEST_ROLE_ADMINISTRATOR', 'ZEBRA_ROLE'])
        ->and($response->json('meta.total'))->toBe(4);
});

it('exposes only the role record itself', function (): void {
    // No permission list, no scope rows, no member count: M1.4 manages role
    // records, and a capability summary belongs to the milestone that owns it.
    $admin = roleAdministrator();

    $response = $this->actingAs($admin)->getJson('/api/v1/roles')->assertOk();

    expect(array_keys($response->json('data.0')))
        ->toBe(['id', 'name', 'guard_name', 'created_at', 'updated_at']);
});

it('returns a single role', function (): void {
    $admin = roleAdministrator();
    $role = makeRole('EXISTING_ROLE');

    $this->actingAs($admin)
        ->getJson("/api/v1/roles/{$role->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', $role->getKey())
        ->assertJsonPath('data.name', 'EXISTING_ROLE');
});

it('returns 404 for a role that does not exist', function (): void {
    $this->actingAs(roleAdministrator())
        ->getJson('/api/v1/roles/999999')
        ->assertNotFound();
});

it('returns 404 rather than an error for a non-numeric role id', function (): void {
    // The package key is an integer; without the route constraint this would
    // reach PostgreSQL as invalid integer input and surface as a 500.
    $this->actingAs(roleAdministrator())
        ->getJson('/api/v1/roles/not-a-number')
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Rename
|--------------------------------------------------------------------------
*/

it('renames a role', function (): void {
    $admin = roleAdministrator();
    $role = makeRole('OLD_NAME');

    $this->actingAs($admin)
        ->patchJson("/api/v1/roles/{$role->getKey()}", ['name' => 'NEW_NAME'])
        ->assertOk()
        ->assertJsonPath('data.name', 'NEW_NAME')
        ->assertJsonPath('data.id', $role->getKey());

    expect($role->fresh()->name)->toBe('NEW_NAME');
});

it('accepts a rename that does not change the name', function (): void {
    $admin = roleAdministrator();
    $role = makeRole('SAME_NAME');

    $this->actingAs($admin)
        ->patchJson("/api/v1/roles/{$role->getKey()}", ['name' => 'SAME_NAME'])
        ->assertOk();
});

it('rejects a rename onto another role\'s name', function (): void {
    $admin = roleAdministrator();
    makeRole('TAKEN_NAME');
    $role = makeRole('MY_NAME');

    $this->actingAs($admin)
        ->patchJson("/api/v1/roles/{$role->getKey()}", ['name' => 'TAKEN_NAME'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('leaves permissions, scopes and memberships untouched by a rename', function (): void {
    // Nothing in the system authorizes by role name, so renaming must not be
    // able to move capability. Asserted directly rather than assumed.
    $admin = roleAdministrator();

    $role = makeRole('BEFORE_RENAME');
    $permission = makePermission('projects.view');
    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OFFICE);

    $member = User::factory()->create();
    $member->assignRole($role);

    $permissionRows = DB::table('role_has_permissions')->orderBy('permission_id')->get()->toArray();
    $scopeRows = DB::table('role_permission_scopes')->orderBy('id')->get()->toArray();
    $membershipRows = DB::table('model_has_roles')->orderBy('role_id')->get()->toArray();

    $this->actingAs($admin)
        ->patchJson("/api/v1/roles/{$role->getKey()}", ['name' => 'AFTER_RENAME'])
        ->assertOk();

    expect(DB::table('role_has_permissions')->orderBy('permission_id')->get()->toArray())->toEqual($permissionRows)
        ->and(DB::table('role_permission_scopes')->orderBy('id')->get()->toArray())->toEqual($scopeRows)
        ->and(DB::table('model_has_roles')->orderBy('role_id')->get()->toArray())->toEqual($membershipRows);

    // And the member's effective access is unchanged by the rename.
    expect(resolveAccess($member->fresh(), 'projects.view')->scopeValues())->toBe(['OFFICE']);
});

it('ignores a guard supplied by the client on rename', function (): void {
    $admin = roleAdministrator();
    $role = makeRole('GUARDED_ROLE');

    $this->actingAs($admin)
        ->patchJson("/api/v1/roles/{$role->getKey()}", ['name' => 'GUARDED_ROLE', 'guard_name' => 'api'])
        ->assertOk();

    expect($role->fresh()->guard_name)->toBe('web');
});

/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/

it('deletes a role nobody holds', function (): void {
    $admin = roleAdministrator();
    $role = makeRole('UNUSED_ROLE');

    $this->actingAs($admin)
        ->deleteJson("/api/v1/roles/{$role->getKey()}")
        ->assertNoContent();

    expect(Role::query()->where('name', 'UNUSED_ROLE')->exists())->toBeFalse();
});

it('removes the deleted role\'s own permission and scope rows', function (): void {
    $admin = roleAdministrator();
    $role = makeRole('UNUSED_ROLE');
    $permission = makePermission('projects.view');
    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);

    $this->actingAs($admin)->deleteJson("/api/v1/roles/{$role->getKey()}")->assertNoContent();

    expect(DB::table('role_has_permissions')->where('role_id', $role->getKey())->count())->toBe(0)
        ->and(RolePermissionScope::query()->where('role_id', $role->getKey())->count())->toBe(0)
        // The permission itself is untouched — it belongs to the registry.
        ->and(Permission::query()->where('name', 'projects.view')->exists())->toBeTrue();
});

it('refuses with 409 to delete a role somebody holds', function (): void {
    $admin = roleAdministrator();
    $role = makeRole('ASSIGNED_ROLE');
    User::factory()->create()->assignRole($role);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/roles/{$role->getKey()}")
        ->assertStatus(409);
});

it('leaves the role and its members intact after a refused delete', function (): void {
    // The refusal must be complete: deleting would cascade model_has_roles and
    // silently strip capability from the people holding the role.
    $admin = roleAdministrator();
    $role = makeRole('ASSIGNED_ROLE');
    $member = User::factory()->create();
    $member->assignRole($role);

    $this->actingAs($admin)->deleteJson("/api/v1/roles/{$role->getKey()}")->assertStatus(409);

    expect(Role::query()->where('name', 'ASSIGNED_ROLE')->exists())->toBeTrue()
        ->and(DB::table('model_has_roles')->where('role_id', $role->getKey())->count())->toBe(1)
        ->and($member->fresh()->hasRole('ASSIGNED_ROLE'))->toBeTrue();
});

it('preserves the effective access of a member after a refused delete', function (): void {
    $admin = roleAdministrator();
    $role = makeRole('ASSIGNED_ROLE');
    $permission = makePermission('projects.view');
    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OFFICE);

    $member = User::factory()->create();
    $member->assignRole($role);

    $this->actingAs($admin)->deleteJson("/api/v1/roles/{$role->getKey()}")->assertStatus(409);

    expect(resolveAccess($member->fresh(), 'projects.view')->scopeValues())->toBe(['OFFICE']);
});

it('refuses to delete a role held by any model, not only a user', function (): void {
    // Roles are attached polymorphically, so the guard reads the pivot rather
    // than a users relation.
    $admin = roleAdministrator();
    $role = makeRole('ASSIGNED_ROLE');

    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => 'App\Models\SomeOtherModel',
        'model_id' => (string) Str::ulid(),
    ]);

    $this->actingAs($admin)->deleteJson("/api/v1/roles/{$role->getKey()}")->assertStatus(409);

    expect(Role::query()->where('name', 'ASSIGNED_ROLE')->exists())->toBeTrue();
});

it('gives a default role name no protection from deletion', function (string $roleName): void {
    // Defaults are a starting configuration, not immutable records.
    $admin = roleAdministrator();
    $role = makeRole($roleName);

    $this->actingAs($admin)->deleteJson("/api/v1/roles/{$role->getKey()}")->assertNoContent();

    expect(Role::query()->where('name', $roleName)->exists())->toBeFalse();
})->with(['SUPER_ADMIN', 'PRINCIPAL', 'ARCHIVE_STAFF', 'AUDITOR']);

/*
|--------------------------------------------------------------------------
| Nothing was added to the schema
|--------------------------------------------------------------------------
*/

it('keeps the package integer key for roles', function (): void {
    $role = makeRole('EXISTING_ROLE');

    expect($role->getKeyType())->toBe('int')
        ->and($role->getIncrementing())->toBeTrue()
        ->and($role->getKey())->toBeInt();
});

it('introduces no additional role table or column', function (): void {
    expect(Schema::hasTable('role_metadata'))->toBeFalse()
        ->and(Schema::hasTable('application_roles'))->toBeFalse();

    // The package's own columns, unchanged: no code, slug, display_name,
    // organization_id, or office_id was added.
    $columns = Schema::getColumnListing('roles');
    sort($columns);

    expect($columns)->toBe(['created_at', 'guard_name', 'id', 'name', 'updated_at']);
});

it('exposes no role member route and no scope route', function (): void {
    // M1.6 added the permission matrix endpoints under this prefix; they are
    // guarded by permissions.view / permissions.assign rather than roles.*
    // (D-054). Membership is still addressed from the user side, and Data Scope
    // has no route of its own — it is part of a permission grant, never a
    // separate resource.
    $roleRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->filter(fn (string $route): bool => str_contains($route, 'api/v1/roles'))
        ->unique()->values()->sort()->values()->all();

    expect($roleRoutes)->toBe([
        'DELETE api/v1/roles/{role}',
        'GET|HEAD api/v1/roles',
        'GET|HEAD api/v1/roles/{role}',
        'GET|HEAD api/v1/roles/{role}/permissions',
        'POST api/v1/roles',
        'PUT api/v1/roles/{role}/permissions',
        'PUT|PATCH api/v1/roles/{role}',
    ]);
});
