<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Two Offices under one Organization, with a user in each — the shape every
 * cross-office assertion below needs.
 *
 * @return array{0: Office, 1: Office}
 */
function twoOffices(): array
{
    $organization = Organization::factory()->create();

    return [
        Office::factory()->for($organization)->create(['name' => 'Office A']),
        Office::factory()->for($organization)->create(['name' => 'Office B']),
    ];
}

/*
|--------------------------------------------------------------------------
| users.view
|--------------------------------------------------------------------------
*/

it('rejects unauthenticated access to every user endpoint', function (): void {
    $target = User::factory()->create();

    expect($this->getJson('/api/v1/users')->status())->toBe(401)
        ->and($this->getJson("/api/v1/users/{$target->getKey()}")->status())->toBe(401)
        ->and($this->postJson('/api/v1/users', [])->status())->toBe(401)
        ->and($this->patchJson("/api/v1/users/{$target->getKey()}", [])->status())->toBe(401)
        ->and($this->postJson("/api/v1/users/{$target->getKey()}/disable")->status())->toBe(401)
        ->and($this->postJson("/api/v1/users/{$target->getKey()}/enable")->status())->toBe(401)
        ->and($this->getJson('/api/v1/users/options')->status())->toBe(401);
});

it('forbids listing users without any permission', function (): void {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/users')->assertForbidden();
});

it('lists every user at the ALL scope', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    User::factory()->for($officeA)->create();
    User::factory()->for($officeB)->create();

    $response = $this->actingAs($actor)->getJson('/api/v1/users')->assertOk();

    expect($response->json('meta.total'))->toBe(3);
});

it('lists only same-office users at the OFFICE scope', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::OFFICE);

    $colleague = User::factory()->for($officeA)->create();
    $stranger = User::factory()->for($officeB)->create();

    $response = $this->actingAs($actor)->getJson('/api/v1/users')->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($actor->getKey(), $colleague->getKey())
        ->and($ids)->not->toContain($stranger->getKey())
        ->and($response->json('meta.total'))->toBe(2);
});

it('does not leak another office\'s user through the detail endpoint', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::OFFICE);

    $stranger = User::factory()->for($officeB)->create();

    $this->actingAs($actor)->getJson("/api/v1/users/{$stranger->getKey()}")->assertForbidden();
});

it('lists only the actor at the OWN scope', function (): void {
    [$officeA] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::OWN);

    $colleague = User::factory()->for($officeA)->create();

    $response = $this->actingAs($actor)->getJson('/api/v1/users')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$actor->getKey()]);

    $this->actingAs($actor)->getJson("/api/v1/users/{$actor->getKey()}")->assertOk();
    $this->actingAs($actor)->getJson("/api/v1/users/{$colleague->getKey()}")->assertForbidden();
});

it('grants no user visibility from ASSIGNED or TEAM alone', function (DataScope $scope): void {
    // Neither predicate has anything to match on a User record.
    $actor = User::factory()->create();
    grantPermissionScope($actor, 'users.view', $scope);

    User::factory()->create();

    $this->actingAs($actor)->getJson('/api/v1/users')->assertForbidden();
})->with(['ASSIGNED' => DataScope::ASSIGNED, 'TEAM' => DataScope::TEAM]);

it('unions OWN and OFFICE into self plus colleagues', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::OWN);
    grantPermissionScope($actor, 'users.view', DataScope::OFFICE);

    $colleague = User::factory()->for($officeA)->create();
    $stranger = User::factory()->for($officeB)->create();

    $ids = collect($this->actingAs($actor)->getJson('/api/v1/users')->json('data'))->pluck('id')->all();

    expect($ids)->toContain($actor->getKey(), $colleague->getKey())
        ->and($ids)->not->toContain($stranger->getKey());
});

it('treats OFFICE and ALL as independent predicates', function (): void {
    // ALL matches everyone on its own, so the union reaches every user — not
    // because it outranks OFFICE, but because it independently matches.
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::OFFICE);
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    User::factory()->for($officeB)->create();

    expect(resolveAccess($actor, 'users.view')->scopeValues())->toBe(['OFFICE', 'ALL'])
        ->and($this->actingAs($actor)->getJson('/api/v1/users')->json('meta.total'))->toBe(2);
});

it('forbids listing while an active DENY override stands', function (): void {
    $actor = User::factory()->create();
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    makeOverride($actor, Permission::findByName('users.view'), UserPermissionEffect::DENY);

    $this->actingAs($actor)->getJson('/api/v1/users')->assertForbidden();
});

it('narrows to the office predicate under an active ALLOW override at OFFICE', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    User::factory()->for($officeB)->create();

    makeOverride($actor, Permission::findByName('users.view'), UserPermissionEffect::ALLOW, DataScope::OFFICE);

    // The override replaces the role result, so ALL is gone.
    expect($this->actingAs($actor)->getJson('/api/v1/users')->json('meta.total'))->toBe(1);
});

it('widens to every user under an active ALLOW override at ALL', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    User::factory()->for($officeB)->create();

    makeOverride($actor, makePermission('users.view'), UserPermissionEffect::ALLOW, DataScope::ALL);

    expect($this->actingAs($actor)->getJson('/api/v1/users')->json('meta.total'))->toBe(2);
});

it('forbids a permission attached directly through the package', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo(makePermission('users.view'));

    expect($actor->hasDirectPermission('users.view'))->toBeTrue();

    $this->actingAs($actor)->getJson('/api/v1/users')->assertForbidden();
});

it('gives a role named SUPER_ADMIN no user visibility', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole(makeRole('SUPER_ADMIN'));

    $this->actingAs($actor)->getJson('/api/v1/users')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| users.create
|--------------------------------------------------------------------------
*/

/** @return array<string, mixed> */
function newUserPayload(Office $office, string $email = 'new.user@example.test'): array
{
    return [
        'name' => 'New User',
        'email' => $email,
        'office_id' => $office->getKey(),
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ];
}

it('creates a user in any active office at the ALL scope', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.create', DataScope::ALL);

    $this->actingAs($actor)->postJson('/api/v1/users', newUserPayload($officeB))->assertCreated();
});

it('creates a user in the actor\'s own office at the OFFICE scope', function (): void {
    [$officeA] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.create', DataScope::OFFICE);

    $this->actingAs($actor)->postJson('/api/v1/users', newUserPayload($officeA))->assertCreated();
});

it('refuses to create a user in another office at the OFFICE scope', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.create', DataScope::OFFICE);

    $this->actingAs($actor)->postJson('/api/v1/users', newUserPayload($officeB))->assertForbidden();

    expect(User::query()->where('email', 'new.user@example.test')->exists())->toBeFalse();
});

it('cannot create a user from a non-administrative scope', function (DataScope $scope): void {
    [$officeA] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.create', $scope);

    $this->actingAs($actor)->postJson('/api/v1/users', newUserPayload($officeA))->assertForbidden();
})->with([
    'OWN' => DataScope::OWN,
    'ASSIGNED' => DataScope::ASSIGNED,
    'TEAM' => DataScope::TEAM,
]);

/*
|--------------------------------------------------------------------------
| users.update
|--------------------------------------------------------------------------
*/

it('updates a user in another office at the ALL scope', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.update', DataScope::ALL);

    $target = User::factory()->for($officeB)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$target->getKey()}", ['name' => 'Renamed'])
        ->assertOk();

    expect($target->fresh()->name)->toBe('Renamed');
});

it('reassigns a user to another active office at the ALL scope', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.update', DataScope::ALL);

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$target->getKey()}", ['office_id' => $officeB->getKey()])
        ->assertOk();

    expect($target->fresh()->office_id)->toBe($officeB->getKey());
});

it('updates a same-office user at the OFFICE scope', function (): void {
    [$officeA] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.update', DataScope::OFFICE);

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$target->getKey()}", ['name' => 'Renamed'])
        ->assertOk();
});

it('refuses to update a user in another office at the OFFICE scope', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.update', DataScope::OFFICE);

    $target = User::factory()->for($officeB)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$target->getKey()}", ['name' => 'Renamed'])
        ->assertForbidden();

    expect($target->fresh()->name)->not->toBe('Renamed');
});

it('refuses to move a user out of the actor\'s office at the OFFICE scope', function (): void {
    // The target is reachable, the destination is not.
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.update', DataScope::OFFICE);

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$target->getKey()}", ['office_id' => $officeB->getKey()])
        ->assertForbidden();

    expect($target->fresh()->office_id)->toBe($officeA->getKey());
});

it('refuses an administrative update from the OWN scope', function (): void {
    // Editing your own administrative record is self-service, not
    // administration — that is M1.8, with its own capability.
    $actor = User::factory()->create();
    grantPermissionScope($actor, 'users.update', DataScope::OWN);

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$actor->getKey()}", ['name' => 'Renamed'])
        ->assertForbidden();
});

it('refuses an update from a direct package permission', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo(makePermission('users.update'));

    $target = User::factory()->for($actor->office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$target->getKey()}", ['name' => 'Renamed'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Activation
|--------------------------------------------------------------------------
*/

it('disables a user at the ALL scope', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.disable', DataScope::ALL);

    $target = User::factory()->for($officeB)->create();

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/disable")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($target->fresh()->is_active)->toBeFalse();
});

it('disables a same-office user at the OFFICE scope', function (): void {
    [$officeA] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.disable', DataScope::OFFICE);

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/disable")->assertOk();

    expect($target->fresh()->is_active)->toBeFalse();
});

it('refuses to disable a user in another office at the OFFICE scope', function (): void {
    [$officeA, $officeB] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.disable', DataScope::OFFICE);

    $target = User::factory()->for($officeB)->create();

    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/disable")->assertForbidden();

    expect($target->fresh()->is_active)->toBeTrue();
});

it('refuses self-disable even at the ALL scope', function (): void {
    // Authorized, but refused: disabling yourself ends your own access and can
    // leave nobody able to undo it. 409, not 403.
    $actor = User::factory()->create();
    grantPermissionScope($actor, 'users.disable', DataScope::ALL);

    $this->actingAs($actor)->postJson("/api/v1/users/{$actor->getKey()}/disable")->assertStatus(409);

    expect($actor->fresh()->is_active)->toBeTrue();
});

it('keeps a disabled user out of authentication', function (): void {
    [$officeA] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.disable', DataScope::OFFICE);

    $target = User::factory()->for($officeA)->create(['password' => 'a-known-password']);

    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/disable")->assertOk();

    // LoginRequest folds is_active into the credential lookup, so the account
    // fails exactly as a wrong password would.
    $this->postJson('/login', ['email' => $target->email, 'password' => 'a-known-password'])
        ->assertStatus(422);
});

it('enables a disabled user', function (): void {
    [$officeA] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.disable', DataScope::OFFICE);

    $target = User::factory()->for($officeA)->create();
    $target->is_active = false;
    $target->save();

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/enable")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    expect($target->fresh()->is_active)->toBeTrue();
});

it('requires the disable capability to enable', function (): void {
    [$officeA] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.update', DataScope::ALL);

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/enable")->assertForbidden();
});

it('is idempotent in both directions', function (): void {
    [$officeA] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.disable', DataScope::OFFICE);

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/disable")->assertOk();
    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/disable")->assertOk();

    expect($target->fresh()->is_active)->toBeFalse();

    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/enable")->assertOk();
    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/enable")->assertOk();

    expect($target->fresh()->is_active)->toBeTrue();
});

it('cannot change activation through the update endpoint', function (): void {
    // Activation is a deliberate security action with its own capability, not a
    // field that rides along on an administrative edit.
    [$officeA] = twoOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.update', DataScope::ALL);

    $target = User::factory()->for($officeA)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/users/{$target->getKey()}", ['name' => 'Renamed', 'is_active' => false])
        ->assertOk();

    expect($target->fresh()->is_active)->toBeTrue()
        ->and($target->fresh()->name)->toBe('Renamed');
});
