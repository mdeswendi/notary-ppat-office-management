<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
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

/*
|--------------------------------------------------------------------------
| role_permission_scopes
|--------------------------------------------------------------------------
*/

it('gives a role permission scope a generated ULID primary key', function (): void {
    $scope = grantScope(makeRole('SCOPE_TEST_ROLE'), makePermission('projects.view'), DataScope::OWN);

    expect($scope->getKeyType())->toBe('string')
        ->and($scope->getIncrementing())->toBeFalse()
        ->and(strlen($scope->id))->toBe(26)
        ->and(Str::isUlid($scope->id))->toBeTrue();
});

it('references roles and permissions by their package-native integer keys', function (): void {
    $role = makeRole('SCOPE_TEST_ROLE');
    $permission = makePermission('projects.view');

    // The package keeps auto-incrementing integer keys; converting them would
    // mean editing vendor migrations, which D-023 ruled out.
    expect($role->getKeyType())->toBe('int')
        ->and($permission->getKeyType())->toBe('int');

    $scope = grantScope($role, $permission, DataScope::OWN)->fresh();

    expect($scope->role_id)->toBeInt()
        ->and($scope->role_id)->toBe($role->getKey())
        ->and($scope->permission_id)->toBeInt()
        ->and($scope->permission_id)->toBe($permission->getKey());
});

it('rejects a scope row pointing at a role that does not exist', function (): void {
    $permission = makePermission('projects.view');

    DB::table('role_permission_scopes')->insert([
        'id' => (string) Str::ulid(),
        'role_id' => 999999,
        'permission_id' => $permission->getKey(),
        'scope' => 'OWN',
    ]);
})->throws(QueryException::class);

it('rejects a scope row pointing at a permission that does not exist', function (): void {
    $role = makeRole('SCOPE_TEST_ROLE');

    DB::table('role_permission_scopes')->insert([
        'id' => (string) Str::ulid(),
        'role_id' => $role->getKey(),
        'permission_id' => 999999,
        'scope' => 'OWN',
    ]);
})->throws(QueryException::class);

it('allows only one scope per role per permission', function (): void {
    $role = makeRole('SCOPE_TEST_ROLE');
    $permission = makePermission('projects.view');

    grantScope($role, $permission, DataScope::OWN);
    grantScope($role, $permission, DataScope::OFFICE);
})->throws(QueryException::class);

it('lets one role hold different scopes for different permissions', function (): void {
    $role = makeRole('SCOPE_TEST_ROLE');

    grantScope($role, makePermission('projects.view'), DataScope::OWN);
    grantScope($role, makePermission('tasks.view'), DataScope::OFFICE);

    expect(RolePermissionScope::query()->count())->toBe(2);
});

it('lets different roles hold different scopes for the same permission', function (): void {
    // The D-028 union depends on this being possible.
    $permission = makePermission('projects.view');

    grantScope(makeRole('SCOPE_TEST_ROLE_A'), $permission, DataScope::ASSIGNED);
    grantScope(makeRole('SCOPE_TEST_ROLE_B'), $permission, DataScope::OFFICE);

    expect(RolePermissionScope::query()->count())->toBe(2);
});

it('round-trips every canonical scope value', function (): void {
    $role = makeRole('SCOPE_TEST_ROLE');

    foreach (DataScope::cases() as $case) {
        $permission = makePermission('probe.'.strtolower($case->value).'.view');
        $stored = grantScope($role, $permission, $case)->fresh();

        expect($stored->scope)->toBe($case)
            ->and(DB::table('role_permission_scopes')->where('id', $stored->id)->value('scope'))
            ->toBe($case->value);
    }
});

it('removes scope metadata when its role is deleted', function (): void {
    // Cascade, not restrict: this is derived authorization metadata, and an
    // orphan row in an authorization table is worse than no row.
    $role = makeRole('SCOPE_TEST_ROLE');
    grantScope($role, makePermission('projects.view'), DataScope::OWN);

    $role->delete();

    expect(RolePermissionScope::query()->count())->toBe(0);
});

it('removes scope metadata when its permission is deleted', function (): void {
    $permission = makePermission('projects.view');
    grantScope(makeRole('SCOPE_TEST_ROLE'), $permission, DataScope::OWN);

    $permission->delete();

    expect(RolePermissionScope::query()->count())->toBe(0);
});

it('exposes the role and permission behind a scope row', function (): void {
    $role = makeRole('SCOPE_TEST_ROLE');
    $permission = makePermission('projects.view');

    $scope = grantScope($role, $permission, DataScope::OWN)->fresh();

    expect($scope->role)->toBeInstanceOf(Role::class)
        ->and($scope->role->is($role))->toBeTrue()
        ->and($scope->permission)->toBeInstanceOf(Permission::class)
        ->and($scope->permission->is($permission))->toBeTrue();
});

it('refuses mass assignment of authorization metadata', function (): void {
    // Fully guarded: there is no path from request input to a scope row.
    RolePermissionScope::create([
        'role_id' => makeRole('SCOPE_TEST_ROLE')->getKey(),
        'permission_id' => makePermission('projects.view')->getKey(),
        'scope' => 'ALL',
    ]);
})->throws(MassAssignmentException::class);

/*
|--------------------------------------------------------------------------
| user_permission_overrides
|--------------------------------------------------------------------------
*/

it('gives a user permission override a generated ULID primary key', function (): void {
    $override = makeOverride(User::factory()->create(), makePermission('projects.view'), UserPermissionEffect::DENY);

    expect($override->getKeyType())->toBe('string')
        ->and($override->getIncrementing())->toBeFalse()
        ->and(strlen($override->id))->toBe(26)
        ->and(Str::isUlid($override->id))->toBeTrue();
});

it('references the user by ULID and the permission by integer key', function (): void {
    $user = User::factory()->create();
    $permission = makePermission('projects.view');

    $override = makeOverride($user, $permission, UserPermissionEffect::DENY)->fresh();

    expect($override->user_id)->toBe($user->getKey())
        ->and(strlen($override->user_id))->toBe(26)
        ->and($override->permission_id)->toBeInt()
        ->and($override->permission_id)->toBe($permission->getKey());
});

it('records who created the override', function (): void {
    $subject = User::factory()->create();
    $author = User::factory()->create();

    $override = makeOverride(
        $subject,
        makePermission('projects.view'),
        UserPermissionEffect::DENY,
        createdBy: $author,
    )->fresh();

    expect($override->created_by)->toBe($author->getKey())
        ->and($override->creator->is($author))->toBeTrue()
        ->and($override->user->is($subject))->toBeTrue();
});

it('rejects an override for a user that does not exist', function (): void {
    DB::table('user_permission_overrides')->insert([
        'id' => (string) Str::ulid(),
        'user_id' => (string) Str::ulid(),
        'permission_id' => makePermission('projects.view')->getKey(),
        'effect' => 'DENY',
        'created_by' => User::factory()->create()->getKey(),
    ]);
})->throws(QueryException::class);

it('rejects an override created by a user that does not exist', function (): void {
    DB::table('user_permission_overrides')->insert([
        'id' => (string) Str::ulid(),
        'user_id' => User::factory()->create()->getKey(),
        'permission_id' => makePermission('projects.view')->getKey(),
        'effect' => 'DENY',
        'created_by' => (string) Str::ulid(),
    ]);
})->throws(QueryException::class);

it('allows only one override per user per permission', function (): void {
    $user = User::factory()->create();
    $permission = makePermission('projects.view');

    makeOverride($user, $permission, UserPermissionEffect::DENY);
    makeOverride($user, $permission, UserPermissionEffect::ALLOW, DataScope::OWN);
})->throws(QueryException::class);

it('lets one user hold overrides for different permissions', function (): void {
    $user = User::factory()->create();

    makeOverride($user, makePermission('projects.view'), UserPermissionEffect::DENY);
    makeOverride($user, makePermission('tasks.view'), UserPermissionEffect::ALLOW, DataScope::OWN);

    expect(UserPermissionOverride::query()->count())->toBe(2);
});

it('stores a DENY override with no scope', function (): void {
    // DENY needs no Data Scope to deny, which is why the column is nullable.
    $override = makeOverride(User::factory()->create(), makePermission('projects.view'), UserPermissionEffect::DENY)->fresh();

    expect($override->effect)->toBe(UserPermissionEffect::DENY)
        ->and($override->scope)->toBeNull();
});

it('stores an ALLOW override with an authoritative scope', function (): void {
    $override = makeOverride(
        User::factory()->create(),
        makePermission('projects.view'),
        UserPermissionEffect::ALLOW,
        DataScope::ASSIGNED,
    )->fresh();

    expect($override->effect)->toBe(UserPermissionEffect::ALLOW)
        ->and($override->scope)->toBe(DataScope::ASSIGNED);
});

it('leaves expires_at null for an override that does not expire', function (): void {
    $override = makeOverride(User::factory()->create(), makePermission('projects.view'), UserPermissionEffect::DENY)->fresh();

    expect($override->expires_at)->toBeNull();
});

it('casts expires_at to a date', function (): void {
    $expiry = now()->addDay()->startOfSecond();

    $override = makeOverride(
        User::factory()->create(),
        makePermission('projects.view'),
        UserPermissionEffect::DENY,
        expiresAt: $expiry,
    )->fresh();

    expect($override->expires_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($override->expires_at->equalTo($expiry))->toBeTrue();
});

it('records a creation timestamp but no update timestamp', function (): void {
    // docs/03_DATABASE_ERD.md section 5 lists created_at only. An override is a
    // decision, and a decision that changes is a new decision.
    $override = makeOverride(User::factory()->create(), makePermission('projects.view'), UserPermissionEffect::DENY);

    expect($override->created_at)->not->toBeNull()
        ->and(Schema::hasColumn('user_permission_overrides', 'updated_at'))->toBeFalse()
        ->and(UserPermissionOverride::UPDATED_AT)->toBeNull();
});

it('removes overrides when their subject is deleted', function (): void {
    $user = User::factory()->create();
    makeOverride($user, makePermission('projects.view'), UserPermissionEffect::DENY, createdBy: User::factory()->create());

    $user->delete();

    expect(UserPermissionOverride::query()->count())->toBe(0);
});

it('refuses to delete a user who authored an override', function (): void {
    // Provenance restricts rather than cascades. The permission registry
    // defines no users.delete capability at all, so this mainly states that
    // position at the database level.
    $author = User::factory()->create();
    makeOverride(User::factory()->create(), makePermission('projects.view'), UserPermissionEffect::DENY, createdBy: $author);

    $author->delete();
})->throws(QueryException::class);

it('refuses mass assignment of override metadata', function (): void {
    UserPermissionOverride::create([
        'user_id' => User::factory()->create()->getKey(),
        'permission_id' => makePermission('projects.view')->getKey(),
        'effect' => 'ALLOW',
        'scope' => 'ALL',
    ]);
})->throws(MassAssignmentException::class);

/*
|--------------------------------------------------------------------------
| Migration reversibility
|--------------------------------------------------------------------------
*/

it('migrates, rolls back, and re-migrates cleanly', function (): void {
    // On its own throwaway SQLite file, so rolling back cannot disturb the
    // suite's own database or anything on PostgreSQL.
    $file = tempnam(sys_get_temp_dir(), 'm13').'.sqlite';
    touch($file);

    config(['database.connections.migration_probe' => [
        'driver' => 'sqlite',
        'database' => $file,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);

    try {
        $this->artisan('migrate:fresh', ['--database' => 'migration_probe'])->assertSuccessful();

        $probe = Schema::connection('migration_probe');

        expect($probe->hasTable('role_permission_scopes'))->toBeTrue()
            ->and($probe->hasTable('user_permission_overrides'))->toBeTrue();

        expect($probe->hasColumns('role_permission_scopes', [
            'id', 'role_id', 'permission_id', 'scope', 'created_at', 'updated_at',
        ]))->toBeTrue();

        expect($probe->hasColumns('user_permission_overrides', [
            'id', 'user_id', 'permission_id', 'effect', 'scope', 'expires_at', 'created_by', 'created_at',
        ]))->toBeTrue();

        $this->artisan('migrate:rollback', ['--database' => 'migration_probe', '--step' => 2])->assertSuccessful();

        expect($probe->hasTable('role_permission_scopes'))->toBeFalse()
            ->and($probe->hasTable('user_permission_overrides'))->toBeFalse()
            // Everything M1.1 and earlier built must survive rolling back only
            // the M1.3 step.
            ->and($probe->hasTable('users'))->toBeTrue()
            ->and($probe->hasTable('offices'))->toBeTrue()
            ->and($probe->hasTable('permissions'))->toBeTrue();

        $this->artisan('migrate', ['--database' => 'migration_probe'])->assertSuccessful();

        expect($probe->hasTable('role_permission_scopes'))->toBeTrue()
            ->and($probe->hasTable('user_permission_overrides'))->toBeTrue();
    } finally {
        DB::purge('migration_probe');
        @unlink($file);
    }
});
