<?php

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\SyncCanonicalPermissions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * `GET /api/v1/me` reports effective access — the resolution of D-028 and
 * D-029, not Spatie's raw grant list. This is O-026's resolution (D-062).
 */
function projectionOf(User $user): array
{
    return test()->actingAs($user)->getJson('/api/v1/me')->assertOk()->json('data');
}

/*
|--------------------------------------------------------------------------
| The contract
|--------------------------------------------------------------------------
*/

it('refuses an unauthenticated request', function (): void {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('reports nothing for an account with no grants', function (): void {
    $data = projectionOf(User::factory()->create());

    expect($data['permissions'])->toBe([])
        ->and($data['permission_scopes'])->toBe([]);
});

it('reports a single role grant with its exact scope', function (DataScope $scope): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    grantPermissionScope($user, 'projects.view', $scope);

    $data = projectionOf($user);

    expect($data['permissions'])->toBe(['projects.view'])
        ->and($data['permission_scopes'])->toBe(['projects.view' => [$scope->value]]);
})->with([
    'OWN' => DataScope::OWN,
    'ASSIGNED' => DataScope::ASSIGNED,
    'OFFICE' => DataScope::OFFICE,
    'ALL' => DataScope::ALL,
]);

it('unions the scopes of several roles', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    grantPermissionScope($user, 'projects.view', DataScope::ASSIGNED);
    grantPermissionScope($user, 'projects.view', DataScope::OFFICE);

    expect(projectionOf($user)['permission_scopes'])
        ->toBe(['projects.view' => ['ASSIGNED', 'OFFICE']]);
});

it('de-duplicates a scope granted by more than one role', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    grantPermissionScope($user, 'projects.view', DataScope::OFFICE);

    // A second role granting the same permission at the same scope.
    $permission = Permission::findByName('projects.view');
    $second = makeRole('SECOND_ROLE');
    $second->givePermissionTo($permission);
    grantScope($second, $permission, DataScope::OFFICE);
    $user->assignRole($second);

    expect(projectionOf($user)['permission_scopes'])->toBe(['projects.view' => ['OFFICE']]);
});

it('keeps both OWN and ALL rather than collapsing to one', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    grantPermissionScope($user, 'projects.view', DataScope::ALL);
    grantPermissionScope($user, 'projects.view', DataScope::OWN);

    expect(projectionOf($user)['permission_scopes'])->toBe(['projects.view' => ['OWN', 'ALL']]);
});

/*
|--------------------------------------------------------------------------
| Overrides
|--------------------------------------------------------------------------
*/

it('removes a permission an active DENY override blocks', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    grantPermissionScope($user, 'projects.view', DataScope::ALL);

    makeOverride($user, Permission::findByName('projects.view'), UserPermissionEffect::DENY);

    $data = projectionOf($user);

    expect($data['permissions'])->not->toContain('projects.view')
        ->and($data['permission_scopes'])->not->toHaveKey('projects.view');
});

it('reports an active ALLOW override in place of the role scopes', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    grantPermissionScope($user, 'projects.view', DataScope::ALL);

    makeOverride(
        $user,
        Permission::findByName('projects.view'),
        UserPermissionEffect::ALLOW,
        DataScope::OFFICE,
    );

    expect(projectionOf($user)['permission_scopes'])->toBe(['projects.view' => ['OFFICE']]);
});

it('grants through an ALLOW override where no role grants at all', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    makeOverride(
        $user,
        Permission::findByName('ppat.warkah.verify'),
        UserPermissionEffect::ALLOW,
        DataScope::ASSIGNED,
    );

    expect(projectionOf($user)['permission_scopes'])
        ->toBe(['ppat.warkah.verify' => ['ASSIGNED']]);
});

it('ignores an expired override in both directions', function (UserPermissionEffect $effect): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    grantPermissionScope($user, 'projects.view', DataScope::OWN);

    makeOverride(
        $user,
        Permission::findByName('projects.view'),
        $effect,
        $effect === UserPermissionEffect::ALLOW ? DataScope::ALL : null,
        expiresAt: now()->subMinute(),
    );

    expect(projectionOf($user)['permission_scopes'])->toBe(['projects.view' => ['OWN']]);
})->with([
    'expired DENY' => UserPermissionEffect::DENY,
    'expired ALLOW' => UserPermissionEffect::ALLOW,
]);

it('omits a permission whose ALLOW override carries no scope', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    grantPermissionScope($user, 'projects.view', DataScope::ALL);

    makeOverride($user, Permission::findByName('projects.view'), UserPermissionEffect::ALLOW, scope: null);

    expect(projectionOf($user)['permissions'])->not->toContain('projects.view');
});

/*
|--------------------------------------------------------------------------
| What never appears
|--------------------------------------------------------------------------
*/

it('omits a permission attached directly through the package', function (): void {
    // The heart of O-026: getAllPermissions() counted these, the resolver never
    // did, and the two disagreed.
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    $user->givePermissionTo(Permission::findByName('projects.view'));

    expect($user->hasDirectPermission('projects.view'))->toBeTrue()
        ->and(projectionOf($user)['permissions'])->toBe([]);
});

it('omits a role grant with no scope metadata', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    $role = makeRole('BROKEN_ROLE');
    $role->givePermissionTo(Permission::findByName('projects.view'));
    $user->assignRole($role);

    expect(projectionOf($user)['permissions'])->toBe([]);
});

it('omits a scope row whose grant was removed', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    $role = makeRole('BROKEN_ROLE');
    grantScope($role, Permission::findByName('projects.view'), DataScope::ALL);
    $user->assignRole($role);

    expect(projectionOf($user)['permissions'])->toBe([]);
});

it('omits a stale permission the registry does not declare', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    grantPermissionScope($user, 'legacy.unmanaged.capability', DataScope::ALL);

    expect(PermissionRegistry::has('legacy.unmanaged.capability'))->toBeFalse()
        ->and(projectionOf($user)['permissions'])->toBe([]);
});

it('omits a canonical permission that has no database row', function (): void {
    // The registry declares it; the sync has not been run.
    $user = User::factory()->create();

    expect(Permission::query()->count())->toBe(0)
        ->and(projectionOf($user)['permissions'])->toBe([]);
});

it('omits a grant whose stored scope is not a canonical value', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    $role = makeRole('BROKEN_ROLE');
    $permission = Permission::findByName('projects.view');
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    DB::table('role_permission_scopes')->insert([
        'id' => (string) Str::ulid(),
        'role_id' => $role->getKey(),
        'permission_id' => $permission->getKey(),
        'scope' => 'EVERYTHING',
    ]);

    expect(projectionOf($user)['permissions'])->toBe([]);
});

it('never decides visibility from a role name', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    $user->assignRole(makeRole('SUPER_ADMIN'));

    $data = projectionOf($user);

    expect($data['roles'])->toBe(['SUPER_ADMIN'])
        ->and($data['permissions'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Ordering, and the bootstrapped administrator
|--------------------------------------------------------------------------
*/

it('orders permissions canonically and scopes in documentation order', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    // Granted in an order that is neither canonical nor alphabetical.
    grantPermissionScope($user, 'users.view', DataScope::OFFICE);
    grantPermissionScope($user, 'projects.view', DataScope::ALL);
    grantPermissionScope($user, 'projects.view', DataScope::OWN);

    $data = projectionOf($user);

    $canonical = PermissionRegistry::all();
    $expected = array_values(array_filter(
        $canonical,
        fn (string $code): bool => in_array($code, ['projects.view', 'users.view'], true),
    ));

    expect($data['permissions'])->toBe($expected)
        ->and($data['permission_scopes']['projects.view'])->toBe(['OWN', 'ALL']);
});

it('gives a bootstrapped administrator the whole registry at ALL', function (): void {
    $this->artisan('app:bootstrap')
        ->expectsQuestion('Organization name', 'Kantor Contoh')
        ->expectsQuestion('Office code', 'PST')
        ->expectsQuestion('Office name', 'Kantor Pusat')
        ->expectsQuestion('Administrator name', 'Administrator')
        ->expectsQuestion('Administrator email', 'admin@example.test')
        ->expectsQuestion('Administrator password', 'correct-horse-battery-staple')
        ->expectsQuestion('Confirm administrator password', 'correct-horse-battery-staple')
        ->assertSuccessful();

    $data = projectionOf(User::query()->firstOrFail());

    expect($data['permissions'])->toBe(PermissionRegistry::all())
        ->and($data['permissions'])->toHaveCount(PermissionRegistry::count())
        ->and(collect($data['permission_scopes'])->flatten()->unique()->values()->all())->toBe(['ALL'])
        ->and($data['permission_scopes'])->toHaveCount(PermissionRegistry::count());
});

/*
|--------------------------------------------------------------------------
| One rule, two entry points
|--------------------------------------------------------------------------
*/

it('projects exactly what the single-permission resolver decides', function (): void {
    // The parity guarantee. A deliberately awkward fixture: multi-role unions,
    // an active DENY, an active ALLOW, an expired override, a grant missing its
    // scope, a corrupt scope value, a stale permission, and a direct package
    // grant — then every canonical permission compared both ways.
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    grantPermissionScope($user, 'projects.view', DataScope::OWN);
    grantPermissionScope($user, 'projects.view', DataScope::OFFICE);
    grantPermissionScope($user, 'projects.create', DataScope::ALL);
    grantPermissionScope($user, 'tasks.view', DataScope::ASSIGNED);
    grantPermissionScope($user, 'calendar.view', DataScope::ALL);
    grantPermissionScope($user, 'documents.view', DataScope::ALL);
    grantPermissionScope($user, 'legacy.unmanaged.capability', DataScope::ALL);

    makeOverride($user, Permission::findByName('calendar.view'), UserPermissionEffect::DENY);
    makeOverride($user, Permission::findByName('documents.view'), UserPermissionEffect::ALLOW, DataScope::OWN);
    makeOverride($user, Permission::findByName('tasks.view'), UserPermissionEffect::ALLOW, DataScope::ALL, expiresAt: now()->subHour());
    makeOverride($user, Permission::findByName('ppat.warkah.verify'), UserPermissionEffect::ALLOW, scope: null);

    // A grant with no scope row.
    $broken = makeRole('NO_SCOPE_ROLE');
    $broken->givePermissionTo(Permission::findByName('reports.export'));
    $user->assignRole($broken);

    // A grant with a corrupt scope value.
    $corrupt = makeRole('CORRUPT_SCOPE_ROLE');
    $corruptPermission = Permission::findByName('audit.view');
    $corrupt->givePermissionTo($corruptPermission);
    $user->assignRole($corrupt);
    DB::table('role_permission_scopes')->insert([
        'id' => (string) Str::ulid(),
        'role_id' => $corrupt->getKey(),
        'permission_id' => $corruptPermission->getKey(),
        'scope' => 'NOT_A_SCOPE',
    ]);

    $user->givePermissionTo(Permission::findByName('billing.view'));

    $resolver = app(EffectiveAccessResolver::class);
    $fresh = $user->fresh();

    $bulk = $resolver->resolveAll($fresh);

    foreach (PermissionRegistry::all() as $permission) {
        $single = $resolver->resolve($fresh, $permission);
        $projected = $bulk[$permission] ?? null;

        if (! $single->granted) {
            expect($projected)->toBeNull("[{$permission}] denied singly but present in bulk");

            continue;
        }

        expect($projected)->not->toBeNull("[{$permission}] granted singly but absent from bulk");
        expect($projected->granted)->toBeTrue()
            ->and($projected->scopeValues())->toBe($single->scopeValues())
            ->and($projected->source)->toBe($single->source);
    }

    // And the fixture genuinely exercised both outcomes.
    expect(array_keys($bulk))->toContain('projects.view', 'projects.create', 'documents.view')
        ->and(array_keys($bulk))->not->toContain('calendar.view', 'reports.export', 'audit.view', 'billing.view', 'ppat.warkah.verify')
        ->and($bulk['projects.view']->scopeValues())->toBe(['OWN', 'OFFICE'])
        ->and($bulk['documents.view']->scopeValues())->toBe(['OWN'])
        ->and($bulk['tasks.view']->scopeValues())->toBe(['ASSIGNED']);
});

/*
|--------------------------------------------------------------------------
| Cost and side effects
|--------------------------------------------------------------------------
*/

it('does not scale its query count with the size of the registry', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();

    grantPermissionScope($user, 'projects.view', DataScope::OFFICE);

    $resolver = app(EffectiveAccessResolver::class);
    $fresh = $user->fresh();

    $count = function (callable $work): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $work();

        return $queries;
    };

    $one = $count(fn () => $resolver->resolve($fresh, 'projects.view'));
    $all = $count(fn () => $resolver->resolveAll($fresh));

    // Resolving the whole catalogue costs the same handful of queries as
    // resolving one. Anything proportional would mean the projection re-derives
    // state per permission.
    expect($all)->toBeLessThanOrEqual($one)
        ->and($all)->toBeLessThan(10)
        ->and(PermissionRegistry::count())->toBeGreaterThan(100);
});

it('writes nothing while reporting the current user', function (): void {
    $user = User::factory()->create();
    app(SyncCanonicalPermissions::class)->handle();
    grantPermissionScope($user, 'projects.view', DataScope::OFFICE);
    makeOverride($user, Permission::findByName('tasks.view'), UserPermissionEffect::DENY, expiresAt: now()->subDay());

    $tables = ['permissions', 'roles', 'role_has_permissions', 'model_has_roles',
        'model_has_permissions', 'role_permission_scopes', 'user_permission_overrides', 'users'];

    $before = collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()]);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    foreach ($statements as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }

    // The expired override in particular is still there: nothing tidies up.
    expect(collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all())
        ->toBe($before->all());
});

it('builds the payload without Spatie\'s permission aggregation', function (): void {
    $source = file_get_contents(app_path('Http/Resources/UserResource.php'));

    // Comments discuss it at length; the code must not call it.
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    expect($code)->not->toContain('getAllPermissions')
        ->and($code)->not->toContain('hasPermissionTo')
        ->and($code)->toContain('resolveAll');
});
