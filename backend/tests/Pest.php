<?php

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Identity\TwoFactor;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Authorization fixtures for the M1.3 tests.
 *
 * `RolePermissionScope` and `UserPermissionOverride` are fully guarded on
 * purpose — every column is an authorization decision, so there is no mass
 * assignment path for request input to reach. These helpers do the explicit
 * property assignment once instead of in forty tests.
 *
 * Tests that need deliberately corrupt data (an unknown scope string, an
 * unknown effect) bypass these and insert through the query builder, since the
 * enum casts would reject the value long before the resolver saw it.
 */
function makeRole(string $name): Role
{
    return Role::create(['name' => $name, 'guard_name' => 'web']);
}

function makePermission(string $name): Permission
{
    return Permission::create(['name' => $name, 'guard_name' => 'web']);
}

function grantScope(
    Role $role,
    Permission $permission,
    DataScope $scope,
): RolePermissionScope {
    $row = new RolePermissionScope;
    $row->role_id = $role->getKey();
    $row->permission_id = $permission->getKey();
    $row->scope = $scope;
    $row->save();

    return $row;
}

function makeOverride(
    User $user,
    Permission $permission,
    UserPermissionEffect $effect,
    ?DataScope $scope = null,
    ?DateTimeInterface $expiresAt = null,
    ?User $createdBy = null,
): UserPermissionOverride {
    $row = new UserPermissionOverride;
    $row->user_id = $user->getKey();
    $row->permission_id = $permission->getKey();
    $row->effect = $effect;
    $row->scope = $scope;
    $row->expires_at = $expiresAt;
    $row->created_by = ($createdBy ?? $user)->getKey();
    $row->save();

    return $row;
}

function resolveAccess(User $user, string $permission): EffectiveAccess
{
    return app(EffectiveAccessResolver::class)->resolve($user, $permission);
}

/**
 * How many rollback steps reach back to a given migration, inclusive.
 *
 * Rollback probes want to undo a specific migration and assert that everything
 * before it survives. A hardcoded `--step` silently starts testing different
 * migrations the moment a later milestone adds one, so the count is derived
 * from the migration filenames instead.
 */
function rollbackStepsTo(string $migrationNameFragment): int
{
    $migrations = collect(glob(database_path('migrations').'/*.php'))
        ->map(fn (string $path): string => basename($path))
        ->sort()
        ->values();

    $index = $migrations->search(fn (string $name): bool => str_contains($name, $migrationNameFragment));

    if ($index === false) {
        throw new RuntimeException("No migration matching [{$migrationNameFragment}].");
    }

    return $migrations->count() - $index;
}

/**
 * Give a user one canonical permission at one Data Scope, through a role.
 *
 * Each (permission, scope) pair gets its own role, so calling this repeatedly
 * builds a genuine multi-role union — which is how D-028 is meant to be
 * exercised — while keeping `role_permission_scopes` unique on
 * (role_id, permission_id).
 */
/**
 * A currently valid TOTP code, produced by the same library the application
 * verifies with.
 *
 * Deliberately not a hand-rolled implementation: a test that computes TOTP
 * differently from the product would either pass on a bug or fail on correct
 * code, and neither tells you anything (D-076).
 */
function totpFor(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/**
 * Put a user into the confirmed two-factor state, bypassing the enrolment
 * endpoints.
 *
 * Returns the secret and the raw recovery codes, which the product itself
 * discloses exactly once and never again.
 *
 * @return array{0: string, 1: array<int, string>}
 */
function enrolTwoFactor(User $user): array
{
    $twoFactor = app(TwoFactor::class);

    $secret = $twoFactor->generateSecret();
    $raw = $twoFactor->generateRecoveryCodes();

    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_setup_expires_at' => null,
        'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($raw),
    ])->save();

    return [$secret, $raw];
}

function grantPermissionScope(User $user, string $permission, DataScope $scope): Role
{
    $permissionModel = Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);

    $role = Role::firstOrCreate([
        'name' => 'TEST_'.str_replace('.', '_', strtoupper($permission)).'_'.$scope->value,
        'guard_name' => 'web',
    ]);

    if (! $role->hasPermissionTo($permissionModel)) {
        $role->givePermissionTo($permissionModel);
        grantScope($role, $permissionModel, $scope);
    }

    $user->assignRole($role);

    return $role;
}
