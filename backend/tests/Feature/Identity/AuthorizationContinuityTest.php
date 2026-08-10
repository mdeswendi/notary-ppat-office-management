<?php

use App\Domains\Authorization\AuthorizationContinuity;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Authorization\SyncCanonicalPermissions;
use App\Models\RolePermissionScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * A user who can administer authorization, through a role of the given name.
 *
 * The name is a parameter precisely because it must never matter: the invariant
 * is capability-based, so `SUPER_ADMIN` and `Bagian Umum` have to behave
 * identically (D-056).
 */
function administratorVia(string $roleName): array
{
    app(SyncCanonicalPermissions::class)->handle();

    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

    foreach (['permissions.view', 'permissions.assign'] as $code) {
        $permission = Permission::findByName($code);

        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
            grantScope($role, $permission, DataScope::ALL);
        }
    }

    $user->assignRole($role);

    return [$user, $role];
}

/**
 * The grant payload that reproduces a role's current configuration, minus any
 * codes named for removal.
 *
 * @param  array<int, string>  $without
 * @return array<int, array{code: string, scope: string}>
 */
function configurationOf(Role $role, array $without = []): array
{
    return DB::table('role_permission_scopes')
        ->join('permissions as p', 'p.id', '=', 'role_permission_scopes.permission_id')
        ->where('role_id', $role->getKey())
        ->get(['p.name as code', 'role_permission_scopes.scope'])
        ->reject(fn ($row): bool => in_array($row->code, $without, true))
        ->map(fn ($row): array => ['code' => $row->code, 'scope' => $row->scope])
        ->values()
        ->all();
}

/*
|--------------------------------------------------------------------------
| The invariant
|--------------------------------------------------------------------------
*/

it('refuses to remove the last permissions.assign grant', function (): void {
    [$admin, $role] = administratorVia('SUPER_ADMIN');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => configurationOf($role, without: ['permissions.assign']),
    ])->assertStatus(409);

    // Rolled back entirely: the configuration is exactly as it was.
    expect($role->fresh()->hasPermissionTo('permissions.assign'))->toBeTrue()
        ->and(RolePermissionScope::query()->where('role_id', $role->getKey())->count())->toBe(2)
        ->and(resolveAccess($admin->fresh(), 'permissions.assign')->scopeValues())->toBe(['ALL']);
});

it('refuses to narrow the last permissions.assign grant from ALL to OFFICE', function (): void {
    // permissions.assign only allows ALL, so this is rejected by validation
    // before continuity is even reached — two independent guards agreeing.
    [$admin, $role] = administratorVia('SUPER_ADMIN');

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => [
            ['code' => 'permissions.view', 'scope' => 'ALL'],
            ['code' => 'permissions.assign', 'scope' => 'OFFICE'],
        ],
    ])->assertStatus(422);

    expect(resolveAccess($admin->fresh(), 'permissions.assign')->scopeValues())->toBe(['ALL']);
});

it('refuses to narrow the last administrator through an override', function (): void {
    // The same loss reached a different way: an ALLOW override replaces the
    // role result, so a narrower one removes the capability.
    [$admin, $role] = administratorVia('SUPER_ADMIN');

    makeOverride(
        $admin,
        Permission::findByName('permissions.assign'),
        UserPermissionEffect::ALLOW,
        DataScope::OFFICE,
    );

    expect(app(AuthorizationContinuity::class)->administratorExists())->toBeFalse();
});

it('refuses to unassign the last administrator\'s only qualifying role', function (): void {
    [$admin, $role] = administratorVia('SUPER_ADMIN');

    $this->actingAs($admin)
        ->putJson("/api/v1/users/{$admin->getKey()}/roles", ['role_ids' => []])
        ->assertStatus(409);

    expect($admin->fresh()->hasRole('SUPER_ADMIN'))->toBeTrue()
        ->and(resolveAccess($admin->fresh(), 'permissions.assign')->scopeValues())->toBe(['ALL']);
});

it('allows an administrator to lose access while another remains', function (): void {
    [$first, $role] = administratorVia('SUPER_ADMIN');
    [$second] = administratorVia('BACKUP_ADMIN');

    $this->actingAs($first)
        ->putJson("/api/v1/users/{$first->getKey()}/roles", ['role_ids' => []])
        ->assertOk();

    expect($first->fresh()->getRoleNames()->all())->toBe([])
        ->and(app(AuthorizationContinuity::class)->administratorExists())->toBeTrue()
        ->and(resolveAccess($second->fresh(), 'permissions.assign')->scopeValues())->toBe(['ALL']);
});

it('does not count a disabled user as an administrator', function (): void {
    // An account that cannot sign in cannot administer anything, so treating it
    // as a safety net would be pretending.
    [$first] = administratorVia('SUPER_ADMIN');
    [$second, $secondRole] = administratorVia('BACKUP_ADMIN');

    $second->is_active = false;
    $second->save();

    $this->actingAs($first)
        ->putJson("/api/v1/users/{$first->getKey()}/roles", ['role_ids' => []])
        ->assertStatus(409);

    expect($first->fresh()->hasRole('SUPER_ADMIN'))->toBeTrue();
});

it('does not count a soft-deleted user as an administrator', function (): void {
    [$first] = administratorVia('SUPER_ADMIN');
    [$second] = administratorVia('BACKUP_ADMIN');

    $second->delete();

    $this->actingAs($first)
        ->putJson("/api/v1/users/{$first->getKey()}/roles", ['role_ids' => []])
        ->assertStatus(409);

    expect($first->fresh()->hasRole('SUPER_ADMIN'))->toBeTrue();
});

it('cares about capability, not the role\'s name', function (string $roleName): void {
    // A role called anything at all satisfies the invariant, and a role called
    // SUPER_ADMIN satisfies it only by holding the capability.
    [$admin, $role] = administratorVia($roleName);

    expect(app(AuthorizationContinuity::class)->administratorExists())->toBeTrue();

    $this->actingAs($admin)->putJson("/api/v1/roles/{$role->getKey()}/permissions", [
        'permissions' => configurationOf($role, without: ['permissions.assign']),
    ])->assertStatus(409);
})->with(['SUPER_ADMIN', 'Bagian Umum', 'custom-administrator', 'AUDITOR']);

it('gives the SUPER_ADMIN name no continuity standing of its own', function (): void {
    [$real, $realRole] = administratorVia('Bagian Umum');

    // Holding the famous name without the capability protects nobody.
    $impostor = User::factory()->create();
    $impostor->assignRole(makeRole('SUPER_ADMIN'));

    $this->actingAs($real)->putJson("/api/v1/roles/{$realRole->getKey()}/permissions", [
        'permissions' => configurationOf($realRole, without: ['permissions.assign']),
    ])->assertStatus(409);
});

/*
|--------------------------------------------------------------------------
| Activation
|--------------------------------------------------------------------------
*/

it('refuses to disable the last authorization administrator', function (): void {
    // M1.5 stopped self-disable; M1.6 made authorization editable, so disabling
    // the last administrator is the same lockout reached another way.
    [$admin, $role] = administratorVia('SUPER_ADMIN');
    grantPermissionScope($admin, 'users.disable', DataScope::ALL);

    [$other] = administratorVia('BACKUP_ADMIN');

    // Remove the backup's capability first, leaving exactly one.
    $this->actingAs($admin)
        ->putJson("/api/v1/users/{$other->getKey()}/roles", ['role_ids' => []])
        ->assertOk();

    $disabler = User::factory()->create();
    grantPermissionScope($disabler, 'users.disable', DataScope::ALL);

    $this->actingAs($disabler)
        ->postJson("/api/v1/users/{$admin->getKey()}/disable")
        ->assertStatus(409);

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('allows disabling an administrator while another capable one remains', function (): void {
    [$first] = administratorVia('SUPER_ADMIN');
    [$second] = administratorVia('BACKUP_ADMIN');

    $disabler = User::factory()->create();
    grantPermissionScope($disabler, 'users.disable', DataScope::ALL);

    $this->actingAs($disabler)->postJson("/api/v1/users/{$first->getKey()}/disable")->assertOk();

    expect($first->fresh()->is_active)->toBeFalse()
        ->and(app(AuthorizationContinuity::class)->administratorExists())->toBeTrue();
});

it('still allows disabling somebody who administers nothing', function (): void {
    [$admin] = administratorVia('SUPER_ADMIN');
    grantPermissionScope($admin, 'users.disable', DataScope::ALL);

    $ordinary = User::factory()->create();

    $this->actingAs($admin)->postJson("/api/v1/users/{$ordinary->getKey()}/disable")->assertOk();

    expect($ordinary->fresh()->is_active)->toBeFalse();
});

it('leaves an empty deployment free to disable users', function (): void {
    // Nobody can administer authorization here, so no change can be what causes
    // the loss. Refusing anyway would make an unprovisioned deployment
    // inexplicably read-only.
    app(SyncCanonicalPermissions::class)->handle();

    $actor = User::factory()->create();
    grantPermissionScope($actor, 'users.disable', DataScope::ALL);

    $target = User::factory()->create();

    expect(app(AuthorizationContinuity::class)->administratorExists())->toBeFalse();

    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/disable")->assertOk();
});

it('enables a user without consulting the invariant', function (): void {
    // Enabling can only ever add an administrator.
    app(SyncCanonicalPermissions::class)->handle();

    $actor = User::factory()->create();
    grantPermissionScope($actor, 'users.disable', DataScope::ALL);

    $target = User::factory()->create();
    $target->is_active = false;
    $target->save();

    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/enable")->assertOk();

    expect($target->fresh()->is_active)->toBeTrue();
});

it('recognizes an administrator granted only through an override', function (): void {
    app(SyncCanonicalPermissions::class)->handle();

    $user = User::factory()->create();

    expect(app(AuthorizationContinuity::class)->administratorExists())->toBeFalse();

    makeOverride(
        $user,
        Permission::findByName('permissions.assign'),
        UserPermissionEffect::ALLOW,
        DataScope::ALL,
    );

    expect(app(AuthorizationContinuity::class)->administratorExists())->toBeTrue();
});

it('does not count a grant that carries no scope metadata', function (): void {
    // The resolver ignores such a grant, so continuity must too — otherwise it
    // would report an administrator who cannot actually administer.
    app(SyncCanonicalPermissions::class)->handle();

    $user = User::factory()->create();
    $role = makeRole('BROKEN_ROLE');
    $role->givePermissionTo(Permission::findByName('permissions.assign'));
    $user->assignRole($role);

    expect(app(AuthorizationContinuity::class)->administratorExists())->toBeFalse();
});
