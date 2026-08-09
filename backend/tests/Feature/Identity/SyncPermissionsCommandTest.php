<?php

use App\Domains\Authorization\PermissionRegistry;
use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * A permission that the canonical registry does not declare, standing in for
 * anything an operator or an earlier release may have left in the table.
 */
const UNMANAGED_PERMISSION = 'legacy.unmanaged.capability';

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('creates every canonical permission', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();

    $stored = Permission::query()->pluck('name')->sort()->values()->all();

    expect($stored)->toBe(PermissionRegistry::all());
});

it('stores permissions on the default guard', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();

    $guards = Permission::query()->pluck('guard_name')->unique()->values()->all();

    expect($guards)->toBe([config('auth.defaults.guard')])
        ->and($guards)->toBe(['web']);
});

it('creates nothing on a second run', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();
    $afterFirstRun = Permission::query()->count();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::query()->count())->toBe($afterFirstRun);
});

it('produces no duplicate rows when run repeatedly', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();
    $this->artisan('permissions:sync')->assertSuccessful();
    $this->artisan('permissions:sync')->assertSuccessful();

    $names = Permission::query()->pluck('name');

    expect($names->count())->toBe($names->unique()->count())
        ->and($names->count())->toBe(count(PermissionRegistry::all()));
});

it('reports that nothing was created when already synchronized', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('Already synchronized')
        ->assertSuccessful();
});

it('fills in only the permissions that are missing', function (): void {
    Permission::create(['name' => 'projects.view', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::query()->count())->toBe(count(PermissionRegistry::all()))
        ->and(Permission::query()->where('name', 'projects.view')->count())->toBe(1);
});

it('leaves an existing permission row untouched', function (): void {
    $existing = Permission::create(['name' => 'projects.view', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::query()->where('name', 'projects.view')->first()->getKey())
        ->toBe($existing->getKey());
});

/*
|--------------------------------------------------------------------------
| Stale permissions
|--------------------------------------------------------------------------
|
| The command cannot distinguish an obsolete leftover from something an
| operator added deliberately, and a role may already depend on it. It reports;
| a human decides.
|
*/

it('preserves a permission that is absent from the registry', function (): void {
    Permission::create(['name' => UNMANAGED_PERMISSION, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::query()->where('name', UNMANAGED_PERMISSION)->exists())->toBeTrue();
});

it('reports an unmanaged permission by name', function (): void {
    Permission::create(['name' => UNMANAGED_PERMISSION, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('permissions:sync')
        ->expectsOutputToContain(UNMANAGED_PERMISSION)
        ->assertSuccessful();
});

it('keeps a role assignment that points at an unmanaged permission', function (): void {
    $permission = Permission::create(['name' => UNMANAGED_PERMISSION, 'guard_name' => 'web']);
    $role = Role::create(['name' => 'EXISTING_TEST_ROLE', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect($role->fresh()->hasPermissionTo(UNMANAGED_PERMISSION))->toBeTrue();
});

it('ends up with the canonical permissions plus the unmanaged one', function (): void {
    Permission::create(['name' => UNMANAGED_PERMISSION, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::query()->count())->toBe(count(PermissionRegistry::all()) + 1);
});

it('deletes no permission rows', function (): void {
    Permission::create(['name' => UNMANAGED_PERMISSION, 'guard_name' => 'web']);
    Permission::create(['name' => 'another.unmanaged.capability', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $before = Permission::query()->pluck('name')->sort()->values()->all();

    $this->artisan('permissions:sync')->assertSuccessful();

    $after = Permission::query()->pluck('name')->all();

    foreach ($before as $name) {
        expect($after)->toContain($name);
    }
});

/*
|--------------------------------------------------------------------------
| Blast radius
|--------------------------------------------------------------------------
|
| Synchronizing the catalogue must not grant anything to anyone. It creates
| capability names, never holders of those capabilities.
|
*/

it('creates no roles', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Role::query()->count())->toBe(0);
});

it('creates no users', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(User::query()->count())->toBe(0);
});

it('creates no organizations or offices', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Organization::query()->count())->toBe(0)
        ->and(Office::query()->count())->toBe(0);
});

it('creates no role-permission assignments', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(DB::table('role_has_permissions')->count())->toBe(0);
});

it('creates no direct user assignments', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(DB::table('model_has_permissions')->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
});

it('grants nothing to an existing user', function (): void {
    $user = User::factory()->create();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect($user->fresh()->getAllPermissions())->toBeEmpty()
        ->and($user->fresh()->getRoleNames())->toBeEmpty();
});

it('leaves an existing role and its assignments unchanged', function (): void {
    $permission = Permission::create(['name' => 'projects.view', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'EXISTING_TEST_ROLE', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Role::query()->count())->toBe(1)
        ->and(DB::table('role_has_permissions')->count())->toBe(1)
        ->and($role->fresh()->hasPermissionTo('projects.view'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Resolution
|--------------------------------------------------------------------------
*/

it('makes the new permissions immediately resolvable', function (): void {
    // A stale Spatie cache would leave the freshly created rows invisible to
    // the authorization layer until the cache happened to expire.
    $this->artisan('permissions:sync')->assertSuccessful();

    $user = User::factory()->create();
    $user->givePermissionTo('ppat.warkah.verify');

    expect($user->can('ppat.warkah.verify'))->toBeTrue()
        ->and($user->can('ppat.warkah.finalize'))->toBeFalse();
});

it('does not grant a permission the registry never declared', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();

    $user = User::factory()->create();

    expect($user->can('audit.update'))->toBeFalse()
        ->and($user->can('audit.delete'))->toBeFalse()
        ->and(Permission::query()->where('name', 'audit.update')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'audit.delete')->exists())->toBeFalse();
});

it('never synchronizes as a side effect of serving a request', function (): void {
    // Synchronization is a deployment step. Nothing in the HTTP lifecycle may
    // write to the permissions table.
    $this->getJson('/api/v1/health')->assertOk();

    expect(Permission::query()->count())->toBe(0);
});

it('is registered under the documented command name', function (): void {
    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('permissions:sync');
});
