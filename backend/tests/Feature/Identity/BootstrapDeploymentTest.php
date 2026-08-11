<?php

use App\Domains\Authorization\AuthorizationContinuity;
use App\Domains\Authorization\DefaultRoleRegistry;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Models\Office;
use App\Models\Organization;
use App\Models\RolePermissionScope;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const BOOTSTRAP_PASSWORD = 'correct-horse-battery-staple';

/**
 * Drive the interactive prompts. The password is typed at a hidden prompt in
 * real use; here it is supplied the same way any other answer is.
 */
function runBootstrap(string $email = 'admin@example.test'): PendingCommand
{
    return test()->artisan('app:bootstrap')
        ->expectsQuestion('Organization name', 'Kantor Notaris & PPAT Contoh')
        ->expectsQuestion('Office code', 'PST')
        ->expectsQuestion('Office name', 'Kantor Pusat')
        ->expectsQuestion('Administrator name', 'Administrator')
        ->expectsQuestion('Administrator email', $email)
        ->expectsQuestion('Administrator password', BOOTSTRAP_PASSWORD)
        ->expectsQuestion('Confirm administrator password', BOOTSTRAP_PASSWORD);
}

/*
|--------------------------------------------------------------------------
| A fresh deployment
|--------------------------------------------------------------------------
*/

it('creates one organization and one office', function (): void {
    runBootstrap()->assertSuccessful();

    expect(Organization::query()->count())->toBe(1)
        ->and(Office::query()->count())->toBe(1)
        ->and(Office::query()->value('code'))->toBe('PST')
        ->and(Office::query()->first()->organization_id)->toBe(Organization::query()->value('id'))
        ->and(Office::query()->value('is_active'))->toBeTrue();
});

it('synchronizes the canonical permissions', function (): void {
    runBootstrap()->assertSuccessful();

    expect(Permission::query()->where('guard_name', PermissionRegistry::GUARD)->count())
        ->toBe(PermissionRegistry::count())
        ->and(Permission::query()->pluck('name')->sort()->values()->all())
        ->toBe(PermissionRegistry::all());
});

it('creates exactly the nine canonical default roles', function (): void {
    runBootstrap()->assertSuccessful();

    $names = Role::query()->pluck('name')->sort()->values()->all();
    $expected = DefaultRoleRegistry::all();
    sort($expected);

    expect(Role::query()->count())->toBe(9)
        ->and($names)->toBe($expected);
});

it('spells the archive role ARCHIVE_STAFF', function (): void {
    runBootstrap()->assertSuccessful();

    expect(Role::query()->where('name', 'ARCHIVE_STAFF')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'ARCHIVE')->exists())->toBeFalse();
});

it('gives the administrator role every canonical permission at ALL', function (): void {
    runBootstrap()->assertSuccessful();

    $role = Role::query()->where('name', DefaultRoleRegistry::ADMINISTRATOR)->firstOrFail();

    $granted = $role->permissions()->pluck('name')->sort()->values()->all();
    $scopes = RolePermissionScope::query()->where('role_id', $role->getKey())->pluck('scope');

    expect($granted)->toBe(PermissionRegistry::all())
        ->and($granted)->toHaveCount(PermissionRegistry::count())
        ->and($scopes)->toHaveCount(PermissionRegistry::count())
        ->and($scopes->unique()->values()->all())->toBe([DataScope::ALL]);
});

it('gives the administrator role its power through explicit grants, not a bypass', function (): void {
    runBootstrap()->assertSuccessful();

    $role = Role::query()->where('name', DefaultRoleRegistry::ADMINISTRATOR)->firstOrFail();

    // No wildcard, and every grant is a row that can be revoked like any other.
    expect($role->permissions()->pluck('name')->contains('*'))->toBeFalse()
        ->and($role->permissions()->pluck('name')->filter(fn (string $n): bool => str_contains($n, '*'))->all())->toBe([]);

    $gate = app(Gate::class);
    $reflection = new ReflectionClass($gate);

    expect($reflection->getProperty('beforeCallbacks')->getValue($gate))->toBe([])
        ->and($reflection->getProperty('afterCallbacks')->getValue($gate))->toBe([]);
});

it('leaves the other eight roles completely empty', function (): void {
    // The high-level matrix grades modules F / V / A / —, which cannot be
    // turned into 171 codes and scopes without inventing the mapping.
    runBootstrap()->assertSuccessful();

    foreach (DefaultRoleRegistry::withoutPermissions() as $name) {
        $role = Role::query()->where('name', $name)->firstOrFail();

        expect($role->permissions()->count())->toBe(0)
            ->and(RolePermissionScope::query()->where('role_id', $role->getKey())->count())->toBe(0);
    }
});

it('creates the first administrator in the bootstrap office', function (): void {
    runBootstrap()->assertSuccessful();

    $admin = User::query()->firstOrFail();

    expect(User::query()->count())->toBe(1)
        ->and(strlen($admin->id))->toBe(26)
        ->and(Str::isUlid($admin->id))->toBeTrue()
        ->and($admin->office_id)->toBe(Office::query()->value('id'))
        ->and($admin->email)->toBe('admin@example.test')
        ->and($admin->is_active)->toBeTrue();
});

it('hashes the administrator password and never prints it', function (): void {
    runBootstrap()->doesntExpectOutputToContain(BOOTSTRAP_PASSWORD)->assertSuccessful();

    $admin = User::query()->firstOrFail();

    expect($admin->password)->not->toBe(BOOTSTRAP_PASSWORD)
        ->and(Hash::check(BOOTSTRAP_PASSWORD, $admin->password))->toBeTrue();
});

it('offers no default password and no password option', function (): void {
    // A password cannot be supplied on the command line, where a shell history
    // would keep it, and there is no fallback value.
    $definition = Artisan::all()['app:bootstrap']->getDefinition();

    expect(array_keys($definition->getOptions()))->not->toContain('password')
        ->and(array_keys($definition->getArguments()))->not->toContain('password');

    $source = file_get_contents(app_path('Console/Commands/BootstrapDeploymentCommand.php'));

    expect($source)->toContain('secret(');
});

it('gives the administrator capability only through the role', function (): void {
    runBootstrap()->assertSuccessful();

    $admin = User::query()->firstOrFail();

    expect($admin->getRoleNames()->all())->toBe([DefaultRoleRegistry::ADMINISTRATOR])
        ->and(DB::table('model_has_permissions')->count())->toBe(0)
        ->and(DB::table('user_permission_overrides')->count())->toBe(0);
});

it('leaves the administrator able to administer authorization', function (): void {
    runBootstrap()->assertSuccessful();

    $admin = User::query()->firstOrFail();

    expect(resolveAccess($admin, 'permissions.assign')->scopeValues())->toBe(['ALL'])
        ->and(resolveAccess($admin, 'roles.create')->scopeValues())->toBe(['ALL'])
        ->and(resolveAccess($admin, 'users.create')->scopeValues())->toBe(['ALL'])
        ->and(app(AuthorizationContinuity::class)->administratorExists())->toBeTrue();
});

it('produces exactly the documented state', function (): void {
    runBootstrap()->assertSuccessful();

    $adminRole = Role::query()->where('name', DefaultRoleRegistry::ADMINISTRATOR)->firstOrFail();

    expect([
        'organizations' => Organization::query()->count(),
        'offices' => Office::query()->count(),
        'users' => User::query()->count(),
        'roles' => Role::query()->count(),
        'permissions' => Permission::query()->count(),
        'model_has_roles' => DB::table('model_has_roles')->count(),
        'model_has_permissions' => DB::table('model_has_permissions')->count(),
        'user_permission_overrides' => DB::table('user_permission_overrides')->count(),
        'admin_grants' => DB::table('role_has_permissions')->where('role_id', $adminRole->getKey())->count(),
        'admin_scopes' => RolePermissionScope::query()->where('role_id', $adminRole->getKey())->count(),
        'all_scopes' => RolePermissionScope::query()->count(),
    ])->toBe([
        'organizations' => 1,
        'offices' => 1,
        'users' => 1,
        'roles' => 9,
        'permissions' => PermissionRegistry::count(),
        'model_has_roles' => 1,
        'model_has_permissions' => 0,
        'user_permission_overrides' => 0,
        'admin_grants' => PermissionRegistry::count(),
        'admin_scopes' => PermissionRegistry::count(),
        'all_scopes' => PermissionRegistry::count(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Re-running, and refusing to guess
|--------------------------------------------------------------------------
*/

it('changes nothing when run again', function (): void {
    runBootstrap()->assertSuccessful();

    $before = [
        'organizations' => Organization::query()->count(),
        'offices' => Office::query()->count(),
        'users' => User::query()->count(),
        'roles' => Role::query()->count(),
        'permissions' => Permission::query()->count(),
        'grants' => DB::table('role_has_permissions')->count(),
        'scopes' => RolePermissionScope::query()->count(),
        'memberships' => DB::table('model_has_roles')->count(),
    ];

    $this->artisan('app:bootstrap')
        ->expectsOutputToContain('already initialized')
        ->assertSuccessful();

    expect([
        'organizations' => Organization::query()->count(),
        'offices' => Office::query()->count(),
        'users' => User::query()->count(),
        'roles' => Role::query()->count(),
        'permissions' => Permission::query()->count(),
        'grants' => DB::table('role_has_permissions')->count(),
        'scopes' => RolePermissionScope::query()->count(),
        'memberships' => DB::table('model_has_roles')->count(),
    ])->toBe($before);
});

it('does not resurrect a default role an office deleted', function (): void {
    runBootstrap()->assertSuccessful();

    Role::query()->where('name', 'AUDITOR')->delete();

    $this->artisan('app:bootstrap')->assertSuccessful();

    expect(Role::query()->where('name', 'AUDITOR')->exists())->toBeFalse()
        ->and(Role::query()->count())->toBe(8);
});

it('does not resurrect a renamed default role', function (): void {
    runBootstrap()->assertSuccessful();

    $role = Role::query()->where('name', 'FINANCE')->firstOrFail();
    $role->name = 'Keuangan';
    $role->save();

    $this->artisan('app:bootstrap')->assertSuccessful();

    expect(Role::query()->where('name', 'FINANCE')->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'Keuangan')->exists())->toBeTrue()
        ->and(Role::query()->count())->toBe(9);
});

it('aborts without writing when the deployment is partially provisioned', function (): void {
    // An Organization but no Office, no roles, no administrator: something was
    // done here, and guessing how to finish it is not the command's business.
    Organization::factory()->create();

    $this->artisan('app:bootstrap')
        ->expectsOutputToContain('cannot be bootstrapped safely')
        ->assertFailed();

    expect(Organization::query()->count())->toBe(1)
        ->and(Office::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

it('aborts when users already exist without an authorization administrator', function (): void {
    User::factory()->create();

    $this->artisan('app:bootstrap')
        ->expectsOutputToContain('cannot be bootstrapped safely')
        ->assertFailed();

    expect(Organization::query()->count())->toBe(1) // the factory's own
        ->and(Role::query()->count())->toBe(0);
});

it('bootstraps a database that already has its permissions synchronized', function (): void {
    // permissions:sync is a normal deployment step and says nothing about
    // whether identity has been provisioned.
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::query()->count())->toBe(PermissionRegistry::count());

    runBootstrap()->assertSuccessful();

    expect(Organization::query()->count())->toBe(1)
        ->and(Role::query()->count())->toBe(9)
        ->and(Permission::query()->count())->toBe(PermissionRegistry::count());
});

it('rolls back identity provisioning when the administrator cannot be created', function (): void {
    // The failure is injected at the last step rather than staged with
    // pre-existing rows, because any pre-existing row would trip the preflight
    // and abort before the transaction ever opened — which is the intended
    // behaviour, and not what this test is about.
    Event::listen(
        'eloquent.creating: '.User::class,
        fn () => throw new RuntimeException('simulated failure creating the administrator'),
    );

    expect(fn () => runBootstrap()->run())->toThrow(RuntimeException::class);

    // Organization, Office and all nine roles were written before the failure
    // and are gone with it.
    expect(Organization::query()->count())->toBe(0)
        ->and(Office::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(RolePermissionScope::query()->count())->toBe(0)
        ->and(DB::table('role_has_permissions')->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);

    // Permissions are synchronized before the transaction and deliberately
    // survive: they are exactly what a re-run would produce anyway, and the
    // sync is idempotent, so leaving them costs nothing and re-running is clean.
    expect(Permission::query()->count())->toBe(PermissionRegistry::count());
});

it('bootstraps cleanly after a failed attempt', function (): void {
    Event::listen(
        'eloquent.creating: '.User::class,
        fn () => throw new RuntimeException('simulated failure'),
    );

    expect(fn () => runBootstrap()->run())->toThrow(RuntimeException::class);

    Event::forget('eloquent.creating: '.User::class);

    runBootstrap()->assertSuccessful();

    expect(Organization::query()->count())->toBe(1)
        ->and(Role::query()->count())->toBe(9)
        ->and(User::query()->count())->toBe(1)
        ->and(Permission::query()->count())->toBe(PermissionRegistry::count());
});

it('rejects invalid details without writing anything', function (): void {
    $this->artisan('app:bootstrap')
        ->expectsQuestion('Organization name', '')
        ->expectsQuestion('Office code', '')
        ->expectsQuestion('Office name', '')
        ->expectsQuestion('Administrator name', '')
        ->expectsQuestion('Administrator email', 'not-an-email')
        ->expectsQuestion('Administrator password', 'short')
        ->expectsQuestion('Confirm administrator password', 'different')
        ->assertFailed();

    expect(Organization::query()->count())->toBe(0)
        ->and(Office::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The extracted sync service
|--------------------------------------------------------------------------
*/

it('keeps permissions:sync working after the extraction', function (): void {
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::query()->count())->toBe(PermissionRegistry::count());

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('Already synchronized')
        ->assertSuccessful();

    expect(Permission::query()->count())->toBe(PermissionRegistry::count());
});

it('registers no scheduled task that would re-provision anything', function (): void {
    $schedule = app(Schedule::class);

    expect($schedule->events())->toBe([]);
});
