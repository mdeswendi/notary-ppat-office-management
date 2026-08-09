<?php

use App\Domains\Authorization\Enums\AccessSource;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Authorization\PermissionRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** A canonical permission, used wherever the name itself is not the subject. */
const RESOLVER_PERMISSION = 'projects.view';

/** Not in the registry. Stands in for a row permissions:sync deliberately preserved. */
const RESOLVER_STALE_PERMISSION = 'm13.stale.probe';

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/*
|--------------------------------------------------------------------------
| The permission must be canonical and present
|--------------------------------------------------------------------------
*/

it('denies a permission name the registry does not declare', function (): void {
    $access = resolveAccess(User::factory()->create(), 'projects.obliterate');

    expect($access->granted)->toBeFalse()
        ->and($access->scopes)->toBe([])
        ->and($access->source)->toBe(AccessSource::NONE);
});

it('denies a stale database permission even when fully granted', function (): void {
    // permissions:sync preserves rows it no longer recognizes (D-036), so the
    // table is not the authority. Everything a grant needs is present here
    // except registry membership, which is the only thing denying it.
    $user = User::factory()->create();
    $role = makeRole('RESOLVER_ROLE');
    $permission = makePermission(RESOLVER_STALE_PERMISSION);

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::ALL);
    $user->assignRole($role);

    expect(PermissionRegistry::has(RESOLVER_STALE_PERMISSION))->toBeFalse()
        ->and($role->hasPermissionTo(RESOLVER_STALE_PERMISSION))->toBeTrue();

    expect(resolveAccess($user, RESOLVER_STALE_PERMISSION)->granted)->toBeFalse();
});

it('denies a canonical permission that has no database row', function (): void {
    // The name is canonical, the sync has simply not been run. The resolver
    // does not paper over that by creating the row mid-check.
    expect(PermissionRegistry::has(RESOLVER_PERMISSION))->toBeTrue()
        ->and(Permission::query()->count())->toBe(0);

    expect(resolveAccess(User::factory()->create(), RESOLVER_PERMISSION)->granted)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Role grants
|--------------------------------------------------------------------------
*/

it('denies a user who holds no role', function (): void {
    makePermission(RESOLVER_PERMISSION);

    expect(resolveAccess(User::factory()->create(), RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('denies a user whose role does not hold the permission', function (): void {
    $user = User::factory()->create();
    $role = makeRole('RESOLVER_ROLE');
    $other = makePermission('tasks.view');

    $role->givePermissionTo($other);
    grantScope($role, $other, DataScope::ALL);
    $user->assignRole($role);

    makePermission(RESOLVER_PERMISSION);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('denies a role grant that carries no Data Scope', function (): void {
    // Scope is required metadata. Reading its absence as ALL would turn an
    // administrative oversight into a privilege escalation.
    $user = User::factory()->create();
    $role = makeRole('RESOLVER_ROLE');
    $permission = makePermission(RESOLVER_PERMISSION);

    $role->givePermissionTo($permission);
    $user->assignRole($role);

    expect($role->hasPermissionTo(RESOLVER_PERMISSION))->toBeTrue()
        ->and(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('denies scope metadata whose role does not actually hold the permission', function (): void {
    // The mirror of the previous case: metadata without a grant is not a grant.
    $user = User::factory()->create();
    $role = makeRole('RESOLVER_ROLE');
    $permission = makePermission(RESOLVER_PERMISSION);

    grantScope($role, $permission, DataScope::ALL);
    $user->assignRole($role);

    expect($role->hasPermissionTo(RESOLVER_PERMISSION))->toBeFalse()
        ->and(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('denies a role the user does not hold', function (): void {
    $role = makeRole('RESOLVER_ROLE');
    $permission = makePermission(RESOLVER_PERMISSION);

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::ALL);

    expect(resolveAccess(User::factory()->create(), RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('resolves a single role grant to its scope', function (DataScope $scope): void {
    $user = User::factory()->create();
    $role = makeRole('RESOLVER_ROLE');
    $permission = makePermission(RESOLVER_PERMISSION);

    $role->givePermissionTo($permission);
    grantScope($role, $permission, $scope);
    $user->assignRole($role);

    $access = resolveAccess($user, RESOLVER_PERMISSION);

    expect($access->granted)->toBeTrue()
        ->and($access->source)->toBe(AccessSource::ROLE)
        ->and($access->scopes)->toBe([$scope]);
})->with([
    'OWN' => DataScope::OWN,
    'ASSIGNED' => DataScope::ASSIGNED,
    // TEAM resolves like any other value. No Team entity exists, so nothing can
    // yet evaluate it against a record — see the TEAM section below.
    'TEAM' => DataScope::TEAM,
    'OFFICE' => DataScope::OFFICE,
    'ALL' => DataScope::ALL,
]);

/*
|--------------------------------------------------------------------------
| Multi-role union (D-028)
|--------------------------------------------------------------------------
*/

it('unions the scopes of two roles granting the same permission', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    foreach ([DataScope::ASSIGNED, DataScope::OFFICE] as $index => $scope) {
        $role = makeRole('RESOLVER_ROLE_'.$index);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, $scope);
        $user->assignRole($role);
    }

    $access = resolveAccess($user, RESOLVER_PERMISSION);

    expect($access->granted)->toBeTrue()
        ->and($access->scopeValues())->toBe(['ASSIGNED', 'OFFICE']);
});

it('de-duplicates a scope granted by several roles', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    foreach ([DataScope::ASSIGNED, DataScope::OFFICE, DataScope::ASSIGNED] as $index => $scope) {
        $role = makeRole('RESOLVER_ROLE_'.$index);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, $scope);
        $user->assignRole($role);
    }

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['ASSIGNED', 'OFFICE']);
});

it('keeps both OWN and ALL rather than collapsing to the wider one', function (): void {
    // The case a ranking implementation would get wrong. ALL does not absorb
    // OWN: they are different predicates, and a Policy may care which applies.
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    foreach ([DataScope::ALL, DataScope::OWN] as $index => $scope) {
        $role = makeRole('RESOLVER_ROLE_'.$index);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, $scope);
        $user->assignRole($role);
    }

    $access = resolveAccess($user, RESOLVER_PERMISSION);

    expect($access->scopeValues())->toBe(['OWN', 'ALL'])
        ->and($access->hasScope(DataScope::OWN))->toBeTrue()
        ->and($access->hasScope(DataScope::ALL))->toBeTrue();
});

it('returns scopes in canonical order regardless of assignment order', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    foreach ([DataScope::ALL, DataScope::OFFICE, DataScope::OWN] as $index => $scope) {
        $role = makeRole('RESOLVER_ROLE_'.$index);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, $scope);
        $user->assignRole($role);
    }

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['OWN', 'OFFICE', 'ALL']);
});

it('ignores a role grant whose stored scope is not a canonical value', function (): void {
    // Corrupt data costs its own grant and nothing else.
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    $good = makeRole('RESOLVER_ROLE_GOOD');
    $good->givePermissionTo($permission);
    grantScope($good, $permission, DataScope::OWN);
    $user->assignRole($good);

    $bad = makeRole('RESOLVER_ROLE_BAD');
    $bad->givePermissionTo($permission);
    $user->assignRole($bad);

    DB::table('role_permission_scopes')->insert([
        'id' => (string) Str::ulid(),
        'role_id' => $bad->getKey(),
        'permission_id' => $permission->getKey(),
        'scope' => 'EVERYTHING',
    ]);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['OWN']);
});

it('ignores a role belonging to a different guard', function (): void {
    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    // Spatie refuses to build this combination through its own API, so the
    // pivot rows go in directly — the point is that corrupt cross-guard data
    // cannot leak a grant into the web guard.
    $roleId = DB::table('roles')->insertGetId(['name' => 'RESOLVER_API_ROLE', 'guard_name' => 'api']);

    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permission->getKey()]);
    DB::table('model_has_roles')->insert([
        'role_id' => $roleId,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
    ]);
    DB::table('role_permission_scopes')->insert([
        'id' => (string) Str::ulid(),
        'role_id' => $roleId,
        'permission_id' => $permission->getKey(),
        'scope' => 'ALL',
    ]);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Overrides (D-029)
|--------------------------------------------------------------------------
*/

it('lets an active DENY override beat a valid role grant', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::ALL);
    $user->assignRole($role);

    makeOverride($user, $permission, UserPermissionEffect::DENY);

    $access = resolveAccess($user, RESOLVER_PERMISSION);

    expect($access->granted)->toBeFalse()
        ->and($access->scopes)->toBe([])
        ->and($access->source)->toBe(AccessSource::OVERRIDE);
});

it('lets an active DENY override beat several role grants at once', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    foreach ([DataScope::OWN, DataScope::OFFICE, DataScope::ALL] as $index => $scope) {
        $role = makeRole('RESOLVER_ROLE_'.$index);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, $scope);
        $user->assignRole($role);
    }

    makeOverride($user, $permission, UserPermissionEffect::DENY);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('lets an active ALLOW override grant a permission no role grants', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    makeOverride($user, $permission, UserPermissionEffect::ALLOW, DataScope::OFFICE);

    $access = resolveAccess($user, RESOLVER_PERMISSION);

    expect($access->granted)->toBeTrue()
        ->and($access->scopeValues())->toBe(['OFFICE'])
        ->and($access->source)->toBe(AccessSource::OVERRIDE);
});

it('replaces role scopes with the override scope rather than adding to them', function (): void {
    // An override can narrow as well as widen. Here it narrows: the roles would
    // have given OWN and OFFICE, and the result is ASSIGNED alone.
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    foreach ([DataScope::OWN, DataScope::OFFICE] as $index => $scope) {
        $role = makeRole('RESOLVER_ROLE_'.$index);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, $scope);
        $user->assignRole($role);
    }

    makeOverride($user, $permission, UserPermissionEffect::ALLOW, DataScope::ASSIGNED);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['ASSIGNED']);
});

it('denies an ALLOW override that carries no scope', function (): void {
    // Malformed rather than unrestricted. Fail closed.
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);
    $user->assignRole($role);

    makeOverride($user, $permission, UserPermissionEffect::ALLOW, scope: null);

    $access = resolveAccess($user, RESOLVER_PERMISSION);

    expect($access->granted)->toBeFalse()
        ->and($access->source)->toBe(AccessSource::OVERRIDE);
});

it('denies an ALLOW override whose stored scope is not a canonical value', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    DB::table('user_permission_overrides')->insert([
        'id' => (string) Str::ulid(),
        'user_id' => $user->getKey(),
        'permission_id' => $permission->getKey(),
        'effect' => 'ALLOW',
        'scope' => 'EVERYTHING',
        'created_by' => $user->getKey(),
        'created_at' => now(),
    ]);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('denies an override whose effect is not a canonical value', function (): void {
    // A row that exists and cannot be understood must not quietly become
    // "no override" and fall through to the role grants.
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::ALL);
    $user->assignRole($role);

    DB::table('user_permission_overrides')->insert([
        'id' => (string) Str::ulid(),
        'user_id' => $user->getKey(),
        'permission_id' => $permission->getKey(),
        'effect' => 'MAYBE',
        'scope' => 'ALL',
        'created_by' => $user->getKey(),
        'created_at' => now(),
    ]);

    $access = resolveAccess($user, RESOLVER_PERMISSION);

    expect($access->granted)->toBeFalse()
        ->and($access->source)->toBe(AccessSource::OVERRIDE);
});

it('ignores another user\'s override', function (): void {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);
    $user->assignRole($role);

    makeOverride($stranger, $permission, UserPermissionEffect::DENY);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['OWN']);
});

it('ignores an override for a different permission', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);
    $user->assignRole($role);

    makeOverride($user, makePermission('tasks.view'), UserPermissionEffect::DENY);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['OWN']);
});

/*
|--------------------------------------------------------------------------
| Expiry, evaluated at check time
|--------------------------------------------------------------------------
*/

it('ignores an expired DENY override and applies the role scopes', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00'));

    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);
    $user->assignRole($role);

    makeOverride($user, $permission, UserPermissionEffect::DENY, expiresAt: now()->subSecond());

    $access = resolveAccess($user, RESOLVER_PERMISSION);

    expect($access->granted)->toBeTrue()
        ->and($access->scopeValues())->toBe(['OWN'])
        ->and($access->source)->toBe(AccessSource::ROLE);
});

it('ignores an expired ALLOW override and applies the role scopes', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00'));

    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OFFICE);
    $user->assignRole($role);

    makeOverride($user, $permission, UserPermissionEffect::ALLOW, DataScope::ALL, expiresAt: now()->subSecond());

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['OFFICE']);
});

it('treats an expired ALLOW as no grant at all when no role grants the permission', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00'));

    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    makeOverride($user, $permission, UserPermissionEffect::ALLOW, DataScope::ALL, expiresAt: now()->subSecond());

    expect(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('treats an override expiring exactly now as already expired', function (): void {
    // The boundary is strict: expires_at must be in the future to be in force.
    Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00'));

    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);
    $user->assignRole($role);

    makeOverride($user, $permission, UserPermissionEffect::DENY, expiresAt: now());

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['OWN']);
});

it('honours an override that expires in the future', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00'));

    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);
    $user->assignRole($role);

    makeOverride($user, $permission, UserPermissionEffect::DENY, expiresAt: now()->addSecond());

    expect(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('expires an override as time passes without anything else running', function (): void {
    // Correctness never depends on a cleanup job. The same row answers
    // differently purely because the clock moved.
    Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00'));

    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);
    $user->assignRole($role);

    makeOverride($user, $permission, UserPermissionEffect::DENY, expiresAt: now()->addHour());

    expect(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-08-09 14:00:00'));

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['OWN']);
});

/*
|--------------------------------------------------------------------------
| Spatie direct user permissions are not a first-party grant path (D-029)
|--------------------------------------------------------------------------
*/

it('ignores a permission attached directly to the user through the package', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);

    $user->givePermissionTo($permission);

    // The package really did record it — this test would be meaningless
    // otherwise — and the first-party resolver still refuses it.
    expect($user->hasDirectPermission(RESOLVER_PERMISSION))->toBeTrue()
        ->and($user->can(RESOLVER_PERMISSION))->toBeTrue()
        ->and(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('still denies a direct package permission when the user also holds an unrelated role', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo(makePermission('tasks.view'));
    $user->assignRole($role);
    $user->givePermissionTo($permission);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('keeps a direct package permission out of the role scope union', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);
    $user->assignRole($role);

    $user->givePermissionTo($permission);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['OWN']);
});

/*
|--------------------------------------------------------------------------
| No role-name special cases (D-032)
|--------------------------------------------------------------------------
*/

it('gives SUPER_ADMIN no bypass when its grant carries no scope', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('SUPER_ADMIN');

    $role->givePermissionTo($permission);
    $user->assignRole($role);

    expect($user->hasRole('SUPER_ADMIN'))->toBeTrue()
        ->and(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('treats SUPER_ADMIN exactly like any other role when it is granted properly', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('SUPER_ADMIN');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OFFICE);
    $user->assignRole($role);

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['OFFICE']);
});

it('denies SUPER_ADMIN a permission its role was never granted', function (): void {
    $user = User::factory()->create();
    makePermission(RESOLVER_PERMISSION);
    $user->assignRole(makeRole('SUPER_ADMIN'));

    expect(resolveAccess($user, RESOLVER_PERMISSION)->granted)->toBeFalse();
});

it('produces the same result for identical grants under different role names', function (): void {
    $permission = makePermission(RESOLVER_PERMISSION);

    $results = [];

    foreach (['SUPER_ADMIN', 'PRINCIPAL', 'FRONT_OFFICE', 'ROLE_WITH_A_SILLY_NAME'] as $name) {
        $role = makeRole($name);
        $role->givePermissionTo($permission);
        grantScope($role, $permission, DataScope::ASSIGNED);

        $user = User::factory()->create();
        $user->assignRole($role);

        $results[$name] = resolveAccess($user, RESOLVER_PERMISSION)->scopeValues();
    }

    expect(array_unique($results, SORT_REGULAR))->toHaveCount(1)
        ->and($results['SUPER_ADMIN'])->toBe(['ASSIGNED']);
});

/*
|--------------------------------------------------------------------------
| The resolver only reads
|--------------------------------------------------------------------------
*/

it('issues no write statements while resolving', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);
    $user->assignRole($role);
    makeOverride($user, makePermission('tasks.view'), UserPermissionEffect::DENY);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    resolveAccess($user, RESOLVER_PERMISSION);

    expect($statements)->not->toBeEmpty();

    foreach ($statements as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }
});

it('creates no permission row for a canonical name that is missing', function (): void {
    $user = User::factory()->create();

    resolveAccess($user, RESOLVER_PERMISSION);
    resolveAccess($user, 'ppat.warkah.verify');

    expect(Permission::query()->count())->toBe(0);
});

it('leaves every authorization table unchanged', function (): void {
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OWN);
    $user->assignRole($role);

    $tables = [
        'permissions', 'roles', 'role_has_permissions', 'model_has_roles',
        'model_has_permissions', 'role_permission_scopes', 'user_permission_overrides',
    ];

    $before = collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()]);

    resolveAccess($user, RESOLVER_PERMISSION);
    resolveAccess($user, RESOLVER_STALE_PERMISSION);
    resolveAccess($user, 'ppat.warkah.verify');

    $after = collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()]);

    expect($after->all())->toBe($before->all());
});

it('does not degrade into a query per role', function (): void {
    // The union must cost the same whether the user holds one role or many.
    $permission = makePermission(RESOLVER_PERMISSION);

    $count = function (int $roles) use ($permission): int {
        $user = User::factory()->create();

        for ($i = 0; $i < $roles; $i++) {
            $role = makeRole('RESOLVER_ROLE_'.Str::random(8));
            $role->givePermissionTo($permission);
            grantScope($role, $permission, DataScope::OWN);
            $user->assignRole($role);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        resolveAccess($user, RESOLVER_PERMISSION);

        return $queries;
    };

    expect($count(1))->toBe($count(6));
});

/*
|--------------------------------------------------------------------------
| Scope semantics that M1.3 deliberately stops short of
|--------------------------------------------------------------------------
*/

it('returns TEAM without inventing a Team relationship', function (): void {
    // TEAM is reserved vocabulary (07_SECURITY_RULES.md section 10). The
    // resolver reports it faithfully and never converts it to OFFICE; nothing
    // can evaluate it against a record until a Team entity is specified.
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::TEAM);
    $user->assignRole($role);

    $access = resolveAccess($user, RESOLVER_PERMISSION);

    expect($access->scopeValues())->toBe(['TEAM'])
        ->and($access->hasScope(DataScope::OFFICE))->toBeFalse();
});

it('reports OFFICE without consulting the user\'s office', function (): void {
    // OFFICE is capability metadata here. Comparing it against a record's
    // office_id is a Policy's job, and no record type exists yet to compare.
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');
    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::OFFICE);

    $first = User::factory()->create();
    $second = User::factory()->create();

    $first->assignRole($role);
    $second->assignRole($role);

    expect($first->office_id)->not->toBe($second->office_id)
        ->and(resolveAccess($first, RESOLVER_PERMISSION)->scopeValues())
        ->toBe(resolveAccess($second, RESOLVER_PERMISSION)->scopeValues());
});

it('treats ALL as a Data Scope and nothing more', function (): void {
    // ALL lifts the record restriction for one permission. It confers no other
    // permission, so a second capability the roles never granted stays denied.
    $user = User::factory()->create();
    $permission = makePermission(RESOLVER_PERMISSION);
    $role = makeRole('RESOLVER_ROLE');

    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::ALL);
    $user->assignRole($role);

    makePermission('notary.deeds.finalize');

    expect(resolveAccess($user, RESOLVER_PERMISSION)->scopeValues())->toBe(['ALL'])
        ->and(resolveAccess($user, 'notary.deeds.finalize')->granted)->toBeFalse();
});
