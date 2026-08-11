<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An administrator holding every M1.5 capability at the ALL scope, in an
 * Organization with a second Office to move people to.
 *
 * @return array{0: User, 1: Office, 2: Office}
 */
function userAdministrator(): array
{
    $organization = Organization::factory()->create();
    $officeA = Office::factory()->for($organization)->create(['name' => 'Office A']);
    $officeB = Office::factory()->for($organization)->create(['name' => 'Office B']);

    $actor = User::factory()->for($officeA)->create();

    foreach (['users.view', 'users.create', 'users.update', 'users.disable'] as $permission) {
        grantPermissionScope($actor, $permission, DataScope::ALL);
    }

    return [$actor, $officeA, $officeB];
}

/*
|--------------------------------------------------------------------------
| Create
|--------------------------------------------------------------------------
*/

it('creates a user', function (): void {
    [$actor, $officeA] = userAdministrator();

    $response = $this->actingAs($actor)->postJson('/api/v1/users', [
        'name' => 'Budi Santoso',
        'email' => 'budi@example.test',
        'phone' => '021-555-0100',
        'office_id' => $officeA->getKey(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    $response->assertJsonPath('data.name', 'Budi Santoso')
        ->assertJsonPath('data.email', 'budi@example.test')
        ->assertJsonPath('data.phone', '021-555-0100')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.office.id', $officeA->getKey());
});

it('hashes the initial password', function (): void {
    [$actor, $officeA] = userAdministrator();

    $this->actingAs($actor)->postJson('/api/v1/users', [
        'name' => 'Budi',
        'email' => 'budi@example.test',
        'office_id' => $officeA->getKey(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    $stored = User::query()->where('email', 'budi@example.test')->value('password');

    expect($stored)->not->toBe('correct-horse-battery-staple')
        ->and(Hash::check('correct-horse-battery-staple', $stored))->toBeTrue();
});

it('never returns the password or any credential field', function (): void {
    [$actor, $officeA] = userAdministrator();

    $response = $this->actingAs($actor)->postJson('/api/v1/users', [
        'name' => 'Budi',
        'email' => 'budi@example.test',
        'office_id' => $officeA->getKey(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    $body = $response->getContent();

    expect($body)->not->toContain('correct-horse-battery-staple')
        ->and(array_keys($response->json('data')))->toBe([
            'id', 'name', 'email', 'phone', 'is_active', 'preferred_locale',
            'last_login_at', 'created_at', 'updated_at', 'office',
        ]);
});

it('creates an account with no capability of any kind', function (): void {
    [$actor, $officeA] = userAdministrator();

    $rolesBefore = DB::table('model_has_roles')->count();

    $this->actingAs($actor)->postJson('/api/v1/users', [
        'name' => 'Budi',
        'email' => 'budi@example.test',
        'office_id' => $officeA->getKey(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    $created = User::query()->where('email', 'budi@example.test')->firstOrFail();

    expect($created->roles()->count())->toBe(0)
        ->and(DB::table('model_has_permissions')->where('model_id', $created->getKey())->count())->toBe(0)
        ->and(DB::table('user_permission_overrides')->where('user_id', $created->getKey())->count())->toBe(0)
        // Nobody else's assignments moved either.
        ->and(DB::table('model_has_roles')->count())->toBe($rolesBefore);
});

it('rejects a duplicate email', function (): void {
    [$actor, $officeA] = userAdministrator();

    User::factory()->for($officeA)->create(['email' => 'taken@example.test']);

    $this->actingAs($actor)->postJson('/api/v1/users', [
        'name' => 'Budi',
        'email' => 'taken@example.test',
        'office_id' => $officeA->getKey(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects an office that does not exist', function (): void {
    [$actor] = userAdministrator();

    $this->actingAs($actor)->postJson('/api/v1/users', [
        'name' => 'Budi',
        'email' => 'budi@example.test',
        'office_id' => (string) Str::ulid(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertStatus(422)->assertJsonValidationErrors('office_id');
});

it('rejects an inactive office', function (): void {
    // An Office being retired is not somewhere to place a new colleague.
    [$actor, $officeA] = userAdministrator();

    $retired = Office::factory()->for($officeA->organization)->create();
    $retired->is_active = false;
    $retired->save();

    $this->actingAs($actor)->postJson('/api/v1/users', [
        'name' => 'Budi',
        'email' => 'budi@example.test',
        'office_id' => $retired->getKey(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertStatus(422)->assertJsonValidationErrors('office_id');
});

it('rejects a password that fails confirmation', function (): void {
    [$actor, $officeA] = userAdministrator();

    $this->actingAs($actor)->postJson('/api/v1/users', [
        'name' => 'Budi',
        'email' => 'budi@example.test',
        'office_id' => $officeA->getKey(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'something-else',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('accepts a user without a phone number', function (): void {
    [$actor, $officeA] = userAdministrator();

    $this->actingAs($actor)->postJson('/api/v1/users', [
        'name' => 'Budi',
        'email' => 'budi@example.test',
        'office_id' => $officeA->getKey(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated()->assertJsonPath('data.phone', null);
});

it('stores a phone number as written, in any reasonable shape', function (string $phone): void {
    // No country prefix is required and nothing is reformatted.
    [$actor, $officeA] = userAdministrator();

    $this->actingAs($actor)->postJson('/api/v1/users', [
        'name' => 'Budi',
        'email' => uniqid('u').'@example.test',
        'phone' => $phone,
        'office_id' => $officeA->getKey(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated()->assertJsonPath('data.phone', $phone);
})->with(['081234567890', '+62 812 3456 7890', '(021) 555-0100', '021 555 0100 ext 12']);

/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

it('updates the administrative fields', function (): void {
    [$actor, $officeA, $officeB] = userAdministrator();

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)->patchJson("/api/v1/users/{$target->getKey()}", [
        'name' => 'Renamed Person',
        'email' => 'renamed@example.test',
        'phone' => '021-555-0199',
        'office_id' => $officeB->getKey(),
    ])->assertOk();

    $target->refresh();

    expect($target->name)->toBe('Renamed Person')
        ->and($target->email)->toBe('renamed@example.test')
        ->and($target->phone)->toBe('021-555-0199')
        ->and($target->office_id)->toBe($officeB->getKey());
});

it('accepts an update that does not change the email', function (): void {
    [$actor, $officeA] = userAdministrator();

    $target = User::factory()->for($officeA)->create(['email' => 'same@example.test']);

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$target->getKey()}", ['email' => 'same@example.test'])
        ->assertOk();
});

it('rejects an update onto another user\'s email', function (): void {
    [$actor, $officeA] = userAdministrator();

    User::factory()->for($officeA)->create(['email' => 'taken@example.test']);
    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$target->getKey()}", ['email' => 'taken@example.test'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
});

it('clears a phone number when sent as null', function (): void {
    [$actor, $officeA] = userAdministrator();

    $target = User::factory()->for($officeA)->create(['phone' => '021-555-0100']);

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$target->getKey()}", ['phone' => null])
        ->assertOk();

    expect($target->fresh()->phone)->toBeNull();
});

it('discards every field the administrative form does not own', function (): void {
    // Password and security belong to M1.9, locale to M1.8, activation to its
    // own endpoints, and authorization to M1.6. None may ride along here.
    [$actor, $officeA] = userAdministrator();

    $target = User::factory()->for($officeA)->create(['preferred_locale' => 'id']);
    $originalPassword = $target->password;
    $originalVerified = $target->email_verified_at;

    $this->actingAs($actor)->patchJson("/api/v1/users/{$target->getKey()}", [
        'name' => 'Renamed',
        'password' => 'attacker-chosen-password',
        'password_confirmation' => 'attacker-chosen-password',
        'preferred_locale' => 'en',
        'is_active' => false,
        'email_verified_at' => now()->toIso8601String(),
        'last_login_at' => now()->toIso8601String(),
        'deleted_at' => now()->toIso8601String(),
        'guard_name' => 'api',
    ])->assertOk();

    $target->refresh();

    expect($target->name)->toBe('Renamed')
        ->and($target->password)->toBe($originalPassword)
        ->and(Hash::check('attacker-chosen-password', $target->password))->toBeFalse()
        ->and($target->preferred_locale)->toBe('id')
        ->and($target->is_active)->toBeTrue()
        ->and($target->email_verified_at)->toEqual($originalVerified)
        ->and($target->last_login_at)->toBeNull()
        ->and($target->deleted_at)->toBeNull();
});

it('leaves every authorization assignment untouched by an update', function (): void {
    [$actor, $officeA, $officeB] = userAdministrator();

    $target = User::factory()->for($officeA)->create();
    $target->assignRole(makeRole('EXISTING_MEMBERSHIP'));
    $target->givePermissionTo(makePermission('projects.view'));
    makeOverride($target, makePermission('tasks.view'), UserPermissionEffect::DENY, createdBy: $actor);

    $roles = DB::table('model_has_roles')->orderBy('role_id')->get()->toArray();
    $permissions = DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray();
    $overrides = DB::table('user_permission_overrides')->orderBy('id')->get()->toArray();

    $this->actingAs($actor)->patchJson("/api/v1/users/{$target->getKey()}", [
        'name' => 'Renamed',
        'roles' => ['SOME_ROLE'],
        'permissions' => ['projects.create'],
        'office_id' => $officeB->getKey(),
    ])->assertOk();

    expect(DB::table('model_has_roles')->orderBy('role_id')->get()->toArray())->toEqual($roles)
        ->and(DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray())->toEqual($permissions)
        ->and(DB::table('user_permission_overrides')->orderBy('id')->get()->toArray())->toEqual($overrides)
        ->and($target->fresh()->hasRole('EXISTING_MEMBERSHIP'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Read
|--------------------------------------------------------------------------
*/

it('returns a single user with the office attached', function (): void {
    [$actor, $officeA] = userAdministrator();

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)
        ->getJson("/api/v1/users/{$target->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', $target->getKey())
        ->assertJsonPath('data.office.id', $officeA->getKey())
        ->assertJsonPath('data.office.code', $officeA->code);
});

it('exposes no role or permission information', function (): void {
    [$actor, $officeA] = userAdministrator();

    $target = User::factory()->for($officeA)->create();
    $target->assignRole(makeRole('SOME_ROLE'));

    $body = $this->actingAs($actor)->getJson("/api/v1/users/{$target->getKey()}")->getContent();

    expect($body)->not->toContain('SOME_ROLE')
        ->and($body)->not->toContain('roles')
        ->and($body)->not->toContain('permissions');
});

it('returns 404 for a user that does not exist', function (): void {
    [$actor] = userAdministrator();

    $this->actingAs($actor)
        ->getJson('/api/v1/users/'.Str::ulid())
        ->assertNotFound();
});

it('returns 404 rather than an error for a malformed id', function (): void {
    [$actor] = userAdministrator();

    $this->actingAs($actor)->getJson('/api/v1/users/not-a-ulid')->assertNotFound();
});

it('hides a soft-deleted user from route binding', function (): void {
    [$actor, $officeA] = userAdministrator();

    $target = User::factory()->for($officeA)->create();
    $target->delete();

    $this->actingAs($actor)->getJson("/api/v1/users/{$target->getKey()}")->assertNotFound();
    $this->actingAs($actor)->patchJson("/api/v1/users/{$target->getKey()}", ['name' => 'X'])->assertNotFound();
});

it('hides a soft-deleted user from the list', function (): void {
    [$actor, $officeA] = userAdministrator();

    $target = User::factory()->for($officeA)->create();
    $total = $this->actingAs($actor)->getJson('/api/v1/users')->json('meta.total');

    $target->delete();

    expect($this->actingAs($actor)->getJson('/api/v1/users')->json('meta.total'))->toBe($total - 1);
});

/*
|--------------------------------------------------------------------------
| Endpoints that must not exist
|--------------------------------------------------------------------------
*/

it('exposes no user deletion', function (): void {
    [$actor, $officeA] = userAdministrator();

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)
        ->deleteJson("/api/v1/users/{$target->getKey()}")
        ->assertStatus(405);

    expect(User::withTrashed()->whereKey($target->getKey())->exists())->toBeTrue();
});

it('exposes exactly the expected user routes and nothing more', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/users'))
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->unique()->values()->sort()->values()->all();

    // Still no DELETE on a user: accounts are retired, not removed (D-050).
    // The four security routes joined in M1.9, each behind its own canonical
    // permission rather than folded into `users.update`.
    expect($routes)->toBe([
        'DELETE api/v1/users/{user}/sessions',
        'DELETE api/v1/users/{user}/two-factor',
        'GET|HEAD api/v1/users',
        'GET|HEAD api/v1/users/options',
        'GET|HEAD api/v1/users/{user}',
        'GET|HEAD api/v1/users/{user}/roles',
        'GET|HEAD api/v1/users/{user}/sessions',
        'POST api/v1/users',
        'POST api/v1/users/{user}/disable',
        'POST api/v1/users/{user}/enable',
        'POST api/v1/users/{user}/password-reset',
        'PUT api/v1/users/{user}/roles',
        'PUT|PATCH api/v1/users/{user}',
    ]);
});

it('does not put password reset behind the user-management capability', function (): void {
    // The route exists as of M1.9, but it is its own capability: a full user
    // administrator with no users.reset_password is refused (D-071).
    [$actor, $officeA] = userAdministrator();

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/password-reset")
        ->assertForbidden();
});

it('does not put role assignment behind the user-management capability', function (): void {
    // The route exists as of M1.6, but it is permission administration: a full
    // user administrator with no permissions.assign is refused (D-055).
    [$actor, $officeA] = userAdministrator();

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)->getJson("/api/v1/users/{$target->getKey()}/roles")->assertForbidden();
    $this->actingAs($actor)->putJson("/api/v1/users/{$target->getKey()}/roles", ['role_ids' => []])->assertForbidden();
});
