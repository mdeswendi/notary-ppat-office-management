<?php

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps the ULID primary key', function (): void {
    $user = User::factory()->create();

    expect($user->getKeyType())->toBe('string')
        ->and($user->getIncrementing())->toBeFalse()
        ->and(strlen($user->id))->toBe(26)
        ->and(Str::isUlid($user->id))->toBeTrue();
});

it('keeps office_id required', function (): void {
    expect(Schema::hasColumn('users', 'office_id'))->toBeTrue();

    DB::table('users')->insert([
        'id' => (string) Str::ulid(),
        'name' => 'No Office',
        'email' => 'no-office@example.test',
        'password' => 'x',
        'preferred_locale' => 'id',
        'is_active' => true,
    ]);
})->throws(QueryException::class);

it('keeps the office foreign key', function (): void {
    DB::table('users')->insert([
        'id' => (string) Str::ulid(),
        'office_id' => (string) Str::ulid(),
        'name' => 'Ghost Office',
        'email' => 'ghost@example.test',
        'password' => 'x',
        'preferred_locale' => 'id',
        'is_active' => true,
    ]);
})->throws(QueryException::class);

it('adds a nullable phone column', function (): void {
    expect(Schema::hasColumn('users', 'phone'))->toBeTrue();

    $user = User::factory()->create();

    expect($user->fresh()->phone)->toBeNull();

    $user->phone = '021-555-0100';
    $user->save();

    expect($user->fresh()->phone)->toBe('021-555-0100');
});

it('adds a nullable deleted_at column', function (): void {
    expect(Schema::hasColumn('users', 'deleted_at'))->toBeTrue()
        ->and(User::factory()->create()->deleted_at)->toBeNull();
});

it('uses soft deletes on the user model', function (): void {
    $user = User::factory()->create();

    expect(in_array(SoftDeletes::class, class_uses_recursive(User::class), true))->toBeTrue();

    $user->delete();

    // The row survives; only the default query hides it.
    expect(User::query()->whereKey($user->getKey())->exists())->toBeFalse()
        ->and(User::withTrashed()->whereKey($user->getKey())->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $user->getKey())->exists())->toBeTrue();
});

it('preserves the columns earlier milestones established', function (string $column): void {
    expect(Schema::hasColumn('users', $column))->toBeTrue();
})->with([
    'email_verified_at',
    'preferred_locale',
    'is_active',
    'last_login_at',
    'remember_token',
]);

it('adds no ownership column that would compete with the office', function (string $column): void {
    // The Organization is reached through the Office (D-027); roles live in the
    // package pivot; there is no tenancy and no Team.
    expect(Schema::hasColumn('users', $column))->toBeFalse();
})->with(['organization_id', 'role_id', 'tenant_id', 'team_id']);

it('introduces no user-office membership table', function (): void {
    expect(Schema::hasTable('user_offices'))->toBeFalse()
        ->and(Schema::hasTable('office_user'))->toBeFalse();
});

it('leaves the users table with exactly the canonical columns', function (): void {
    $columns = Schema::getColumnListing('users');
    sort($columns);

    // The account-security columns joined in M1.9. Listed here rather than
    // exempted, so a future migration cannot slip an extra credential column
    // onto this table without the inventory saying so.
    expect($columns)->toBe([
        'created_at', 'deleted_at', 'email', 'email_verified_at', 'id', 'is_active',
        'last_login_at', 'name', 'office_id', 'password',
        'pending_email', 'pending_email_requested_at', 'pending_email_token',
        'phone', 'preferred_locale', 'remember_token',
        'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_secret',
        'two_factor_setup_expires_at', 'updated_at',
    ]);
});

it('migrates, rolls back, and re-migrates cleanly', function (): void {
    // On its own throwaway SQLite file, so rolling back cannot disturb the
    // suite's database or anything on PostgreSQL.
    $file = tempnam(sys_get_temp_dir(), 'm15').'.sqlite';
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

        expect($probe->hasColumn('users', 'phone'))->toBeTrue()
            ->and($probe->hasColumn('users', 'deleted_at'))->toBeTrue();

        $this->artisan('migrate:rollback', [
            '--database' => 'migration_probe',
            '--step' => rollbackStepsTo('add_user_management_fields_to_users_table'),
        ])->assertSuccessful();

        expect($probe->hasColumn('users', 'phone'))->toBeFalse()
            ->and($probe->hasColumn('users', 'deleted_at'))->toBeFalse()
            // Everything earlier survives rolling back only this step.
            ->and($probe->hasColumn('users', 'email_verified_at'))->toBeTrue()
            ->and($probe->hasColumn('users', 'office_id'))->toBeTrue()
            ->and($probe->hasTable('role_permission_scopes'))->toBeTrue();

        $this->artisan('migrate', ['--database' => 'migration_probe'])->assertSuccessful();

        expect($probe->hasColumn('users', 'phone'))->toBeTrue()
            ->and($probe->hasColumn('users', 'deleted_at'))->toBeTrue();
    } finally {
        DB::purge('migration_probe');
        @unlink($file);
    }
});

it('keeps the office relationship intact', function (): void {
    $office = Office::factory()->create();
    $user = User::factory()->for($office)->create();

    expect($user->office->is($office))->toBeTrue()
        ->and($office->users()->whereKey($user->getKey())->exists())->toBeTrue();
});
