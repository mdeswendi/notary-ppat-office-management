<?php

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
