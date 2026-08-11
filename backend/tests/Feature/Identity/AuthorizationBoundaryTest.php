<?php

use App\Domains\Authorization\DefaultRoleRegistry;
use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\AccessSource;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Authorization\PermissionRegistry;
use App\Models\User;
use App\Policies\RolePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * The authorization boundary (D-048).
 *
 * One rule, asserted from every direction that could break it: a canonical
 * permission name is never an authorization surface on its own. Access is
 * decided by a Policy that delegates to EffectiveAccessResolver, and nothing
 * else may answer first.
 *
 * These tests are deliberately about the *seam* rather than the resolver's
 * logic, which M1.3 already covers in depth.
 */
beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * The full, correct path: canonical permission, held through a role, with the
 * ALL Data Scope a deployment-global record requires.
 */
function boundaryUser(DataScope $scope = DataScope::ALL, string $permissionName = 'roles.view', string $roleName = 'BOUNDARY_ROLE'): User
{
    $user = User::factory()->create();
    $permission = makePermission($permissionName);
    $role = makeRole($roleName);

    $role->givePermissionTo($permission);
    grantScope($role, $permission, $scope);
    $user->assignRole($role);

    return $user;
}

/*
|--------------------------------------------------------------------------
| The package's generic permission Gate is gone
|--------------------------------------------------------------------------
*/

it('disables the package permission check registration', function (): void {
    expect(config('permission.register_permission_check_method'))->toBeFalse();
});

it('registers no Gate before or after callbacks', function (): void {
    // Zero, not one: with the package's callback unregistered, nothing can
    // answer an ability ahead of the policy.
    $gate = app(Illuminate\Contracts\Auth\Access\Gate::class);
    $reflection = new ReflectionClass($gate);

    expect($reflection->getProperty('beforeCallbacks')->getValue($gate))->toBe([])
        ->and($reflection->getProperty('afterCallbacks')->getValue($gate))->toBe([]);
});

it('refuses a canonical permission name given straight to the Gate', function (): void {
    // The headline regression. This user genuinely holds roles.view at ALL and
    // is allowed through the policy — but naming the permission to the Gate is
    // not an authorization path, so it answers false.
    $user = boundaryUser();

    expect(app(EffectiveAccessResolver::class)->allowsGlobally($user, 'roles.view'))->toBeTrue()
        ->and($user->can('roles.view'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('roles.view'))->toBeFalse()
        ->and(Gate::forUser($user)->denies('roles.view'))->toBeTrue();
});

it('refuses every canonical permission name through the Gate', function (string $permission): void {
    $user = boundaryUser(DataScope::ALL, $permission);

    expect($user->can($permission))->toBeFalse();
})->with(['roles.view', 'roles.create', 'roles.update', 'roles.delete', 'users.view', 'ppat.deeds.approve']);

it('keeps the package storage API working', function (): void {
    // Disabling the Gate integration must not disturb what the package is
    // actually responsible for: storing roles, permissions and their links.
    $user = boundaryUser();

    expect($user->hasRole('BOUNDARY_ROLE'))->toBeTrue()
        ->and($user->hasPermissionTo('roles.view'))->toBeTrue()
        ->and($user->getPermissionsViaRoles()->pluck('name')->all())->toBe(['roles.view'])
        ->and(Role::query()->where('name', 'BOUNDARY_ROLE')->exists())->toBeTrue()
        ->and(PermissionRegistry::has('roles.view'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Policy abilities still work, and still go through the resolver
|--------------------------------------------------------------------------
*/

it('permits a policy ability backed by permission and ALL scope', function (): void {
    $user = boundaryUser();

    expect(Gate::forUser($user)->allows('viewAny', Role::class))->toBeTrue()
        ->and(app(RolePolicy::class)->viewAny($user))->toBeTrue();
});

it('keeps every policy ability functioning', function (): void {
    $user = User::factory()->create();
    $role = makeRole('BOUNDARY_ROLE');

    foreach (['roles.view', 'roles.create', 'roles.update', 'roles.delete'] as $name) {
        $permission = makePermission($name);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, DataScope::ALL);
    }

    $user->assignRole($role);
    $target = makeRole('TARGET_ROLE');

    expect(Gate::forUser($user)->allows('viewAny', Role::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $target))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', Role::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($user)->allows('delete', $target))->toBeTrue();
});

it('reaches the policy through the controller', function (): void {
    $this->actingAs(boundaryUser())->getJson('/api/v1/roles')->assertOk();
});

it('denies the policy ability when the resolver denies', function (): void {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', Role::class))->toBeFalse();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The resolver's rules survive the change
|--------------------------------------------------------------------------
*/

it('still requires the ALL scope for a deployment-global record', function (): void {
    // The permission is held and the resolver reports it — with OFFICE, which a
    // Role definition has nothing to match against.
    $user = boundaryUser(DataScope::OFFICE);

    $access = app(EffectiveAccessResolver::class)->resolve($user, 'roles.view');

    expect($access->granted)->toBeTrue()
        ->and($access->scopeValues())->toBe(['OFFICE'])
        ->and(app(EffectiveAccessResolver::class)->allowsGlobally($user, 'roles.view'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('viewAny', Role::class))->toBeFalse();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
});

it('still ignores a direct package permission', function (): void {
    // Package storage still records it. Neither the Gate nor the policy cares.
    $user = User::factory()->create();
    $user->givePermissionTo(makePermission('roles.view'));

    expect($user->hasDirectPermission('roles.view'))->toBeTrue()
        ->and($user->can('roles.view'))->toBeFalse()
        ->and(app(EffectiveAccessResolver::class)->resolve($user, 'roles.view')->granted)->toBeFalse()
        ->and(Gate::forUser($user)->allows('viewAny', Role::class))->toBeFalse();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
});

it('still ignores a direct package permission held alongside a scoped role grant', function (): void {
    // Belt and braces: the direct grant must not top up a role result either.
    $user = boundaryUser(DataScope::OFFICE);
    $user->givePermissionTo(Permission::findByName('roles.view'));

    expect($user->hasDirectPermission('roles.view'))->toBeTrue()
        ->and(app(EffectiveAccessResolver::class)->resolve($user, 'roles.view')->scopeValues())->toBe(['OFFICE'])
        ->and(Gate::forUser($user)->allows('viewAny', Role::class))->toBeFalse();
});

it('still lets an active DENY override win', function (): void {
    $user = boundaryUser();
    $permission = Permission::findByName('roles.view');

    makeOverride($user, $permission, UserPermissionEffect::DENY);

    $access = app(EffectiveAccessResolver::class)->resolve($user, 'roles.view');

    expect($access->granted)->toBeFalse()
        ->and($access->source)->toBe(AccessSource::OVERRIDE)
        ->and(Gate::forUser($user)->allows('viewAny', Role::class))->toBeFalse();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
});

it('still honours an active ALLOW override carrying ALL', function (): void {
    $user = User::factory()->create();
    $permission = makePermission('roles.view');

    makeOverride($user, $permission, UserPermissionEffect::ALLOW, DataScope::ALL);

    expect(Gate::forUser($user)->allows('viewAny', Role::class))->toBeTrue();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertOk();
});

it('still refuses an active ALLOW override carrying only OFFICE', function (): void {
    $user = boundaryUser();
    $permission = Permission::findByName('roles.view');

    makeOverride($user, $permission, UserPermissionEffect::ALLOW, DataScope::OFFICE);

    expect(Gate::forUser($user)->allows('viewAny', Role::class))->toBeFalse();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
});

it('still ignores an expired override', function (): void {
    $user = boundaryUser();
    $permission = Permission::findByName('roles.view');

    makeOverride($user, $permission, UserPermissionEffect::DENY, expiresAt: now()->subMinute());

    expect(Gate::forUser($user)->allows('viewAny', Role::class))->toBeTrue();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertOk();
});

it('still refuses a stale permission the registry does not declare', function (): void {
    $user = boundaryUser(DataScope::ALL, 'm14a.stale.probe');

    expect(PermissionRegistry::has('m14a.stale.probe'))->toBeFalse()
        ->and($user->hasPermissionTo('m14a.stale.probe'))->toBeTrue()
        ->and(app(EffectiveAccessResolver::class)->resolve($user, 'm14a.stale.probe')->granted)->toBeFalse()
        ->and($user->can('m14a.stale.probe'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Role names remain inert
|--------------------------------------------------------------------------
*/

it('gives a role named SUPER_ADMIN no authorization', function (): void {
    $user = User::factory()->create();
    $user->assignRole(makeRole('SUPER_ADMIN'));

    expect($user->hasRole('SUPER_ADMIN'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewAny', Role::class))->toBeFalse()
        ->and($user->can('roles.view'))->toBeFalse();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertForbidden();
});

it('authorizes any role name that holds the permission at ALL', function (string $roleName): void {
    $user = boundaryUser(DataScope::ALL, 'roles.view', $roleName);

    expect(Gate::forUser($user)->allows('viewAny', Role::class))->toBeTrue();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertOk();
})->with(['SUPER_ADMIN', 'AUDITOR', 'Notaris Pengganti', 'lowercase name']);

/*
|--------------------------------------------------------------------------
| No first-party code reaches for the unsafe path
|--------------------------------------------------------------------------
*/

it('never authorizes a canonical permission name in application code', function (): void {
    // A source-level guard, so the boundary survives the next endpoint someone
    // writes. Comments are stripped first — these files discuss the rule at
    // length, and prose is not behaviour.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file->getPathname()));

        // `can('resource.action')`, `Gate::allows('resource.action')`, and the
        // package's own checks used as an authorization decision.
        $unsafe = '/(->can|->cannot|Gate::allows|Gate::denies|Gate::check|->hasPermissionTo|->hasAnyPermission|->hasAllPermissions|->checkPermissionTo)\s*\(\s*[\'"][a-z0-9_]+\.[a-z0-9_.]+[\'"]/i';

        if (preg_match($unsafe, $code)) {
            $offenders[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

it('never branches on a role name in application code', function (): void {
    // `DefaultRoleRegistry` has always claimed "a test greps for exactly that".
    // Until M1.10 no such test existed — the scan above covers permission codes
    // only, so `hasRole('SUPER_ADMIN')` would have passed it untouched. D-032
    // and D-045 forbid role-name authorization; this is the guard that makes
    // that enforceable rather than merely stated.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    $offenders = [];
    $scanned = 0;

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;

        $relative = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file->getPathname()));

        // Role membership is for display and administration, never a decision.
        if (preg_match('/->(hasRole|hasAnyRole|hasAllRoles)\s*\(/i', $code)) {
            $offenders[] = "{$relative}: role membership predicate";
        }

        // The default role names may appear in exactly one place: the registry
        // that declares them for bootstrap. Anywhere else is a branch waiting
        // to happen.
        if (str_contains($relative, 'DefaultRoleRegistry.php')) {
            continue;
        }

        foreach (DefaultRoleRegistry::all() as $roleName) {
            if (str_contains($code, "'{$roleName}'") || str_contains($code, "\"{$roleName}\"")) {
                $offenders[] = "{$relative}: literal role name {$roleName}";
            }
        }
    }

    // Sentinel: a scan that silently walks nothing reports clean for every rule
    // it enforces, which is worse than not scanning (M1.8 shipped that once).
    expect($scanned)->toBeGreaterThan(50)
        ->and($offenders)->toBe([]);
});

it('keeps the resolver the only thing the role policy consults', function (): void {
    $constructor = (new ReflectionClass(RolePolicy::class))->getConstructor();
    $parameters = $constructor?->getParameters() ?? [];

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getType()?->getName())->toBe(EffectiveAccessResolver::class);
});
