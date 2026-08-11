<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Identity\SessionRegistry;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // phpunit.xml runs the suite on the `array` driver for speed. Session
    // enumeration is only meaningful against the database driver — the one
    // production uses — so these tests opt into it explicitly rather than
    // asserting against a store that cannot be read back (D-074).
    config(['session.driver' => 'database']);

    // Sanctum only treats an API request as stateful when it comes from a
    // configured frontend origin, and only then does `StartSession` run. Without
    // this header the tests would exercise a sessionless path production never
    // takes, and would quietly stop testing the thing they are named after.
    $this->withHeader('Origin', 'http://localhost');
});

/**
 * The opaque keys the API reports for a user, as a plain array.
 *
 * @return array<int, string>
 */
function sessionKeysFor(User $user): array
{
    return app(SessionRegistry::class)->forUser($user)->pluck('key')->all();
}

/**
 * Insert a session row directly, standing in for a browser signed in elsewhere.
 */
function fakeSession(User $user, string $id, ?string $userAgent = null, ?int $lastActivity = null): string
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->getKey(),
        'ip_address' => '203.0.113.7',
        'user_agent' => $userAgent ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
        'payload' => base64_encode(serialize(['_token' => 'a-csrf-token-value'])),
        'last_activity' => $lastActivity ?? now()->getTimestamp(),
    ]);

    return $id;
}

/*
|--------------------------------------------------------------------------
| Listing your own sessions
|--------------------------------------------------------------------------
*/

it('rejects an unauthenticated session listing', function (): void {
    $this->getJson('/api/v1/security/sessions')->assertUnauthorized();
});

it('lists a user\'s own sessions with no permission at all', function (): void {
    $user = User::factory()->create();
    fakeSession($user, 'session-one');

    expect($user->getAllPermissions())->toBeEmpty();

    $keys = $this->actingAs($user)->getJson('/api/v1/security/sessions')
        ->assertOk()
        ->json('data.*.key');

    expect($keys)->toContain(hash('sha256', 'session-one'));
});

it('never exposes a raw session id', function (): void {
    // The id is a credential: anyone holding it can forge the cookie. The API
    // works in SHA-256 digests instead.
    $user = User::factory()->create();
    fakeSession($user, 'a-very-secret-session-id');

    $response = $this->actingAs($user)->getJson('/api/v1/security/sessions');

    expect($response->getContent())->not->toContain('a-very-secret-session-id');

    $keys = $response->json('data.*.key');

    expect($keys)->toContain(hash('sha256', 'a-very-secret-session-id'));

    foreach ($keys as $key) {
        expect(strlen($key))->toBe(64);
    }
});

it('never exposes the session payload or the CSRF token', function (): void {
    $user = User::factory()->create();
    fakeSession($user, 'session-one');

    $response = $this->actingAs($user)->getJson('/api/v1/security/sessions');

    expect($response->getContent())->not->toContain('a-csrf-token-value')
        ->and($response->getContent())->not->toContain('payload');

    foreach ($response->json('data') as $session) {
        expect(array_keys($session))
            ->toBe(['key', 'current', 'ip_address', 'device', 'last_active_at']);
    }
});

it('reduces the user agent to a coarse device label', function (): void {
    // "Was that me?" is answered by a browser and a platform. The full string is
    // a fingerprint the screen has no use for.
    $user = User::factory()->create();
    fakeSession($user, 'session-one', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36');

    $response = $this->actingAs($user)->getJson('/api/v1/security/sessions');

    $row = collect($response->json('data'))
        ->firstWhere('key', hash('sha256', 'session-one'));

    expect($row['device'])->toBe('Chrome — Windows')
        ->and($response->getContent())->not->toContain('AppleWebKit');
});

it('shows only the caller\'s own sessions', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    fakeSession($user, 'mine-one');
    fakeSession($other, 'theirs-one');

    $response = $this->actingAs($user)->getJson('/api/v1/security/sessions');

    expect($response->json('data.*.key'))->toContain(hash('sha256', 'mine-one'))
        ->and($response->getContent())->not->toContain(hash('sha256', 'theirs-one'));

    expect(sessionKeysFor($other))->not->toContain(hash('sha256', 'mine-one'));
});

/*
|--------------------------------------------------------------------------
| Revoking sessions
|--------------------------------------------------------------------------
*/

it('revokes one of the caller\'s own sessions by opaque key', function (): void {
    $user = User::factory()->create();
    fakeSession($user, 'session-to-end');

    $key = app(SessionRegistry::class)->opaqueKey('session-to-end');

    $this->actingAs($user)->deleteJson("/api/v1/security/sessions/{$key}")->assertNoContent();

    expect(DB::table('sessions')->where('id', 'session-to-end')->exists())->toBeFalse();
});

it('answers 404 for an unknown session key rather than pretending', function (): void {
    // Reporting success for a session that was never revoked would tell somebody
    // their old laptop is signed out when it is not.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/security/sessions/'.hash('sha256', 'no-such-session'))
        ->assertNotFound();
});

it('cannot revoke another user\'s session', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    fakeSession($other, 'their-session');

    $key = app(SessionRegistry::class)->opaqueKey('their-session');

    $this->actingAs($user)->deleteJson("/api/v1/security/sessions/{$key}")->assertNotFound();

    expect(DB::table('sessions')->where('id', 'their-session')->exists())->toBeTrue();
});

it('revokes every other session but keeps the caller signed in', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    fakeSession($user, 'elsewhere-one');
    fakeSession($user, 'elsewhere-two');

    $this->actingAs($user)
        ->deleteJson('/api/v1/security/sessions/others', ['current_password' => 'current-password-here'])
        ->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $user->getKey())
        ->whereIn('id', ['elsewhere-one', 'elsewhere-two'])->count())->toBe(0);

    // Still authenticated: securing your account must not sign you out of it.
    $this->actingAs($user)->getJson('/api/v1/me')->assertOk();
});

it('requires the current password to sign out everywhere else', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    fakeSession($user, 'elsewhere-one');

    $this->actingAs($user)
        ->deleteJson('/api/v1/security/sessions/others', ['current_password' => 'wrong-password'])
        ->assertStatus(422);

    expect(DB::table('sessions')->where('id', 'elsewhere-one')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Sessions and credential changes
|--------------------------------------------------------------------------
*/

it('revokes other sessions when the password changes', function (): void {
    // Changing a password is usually a response to suspecting somebody else has
    // it. Leaving their session alive would make the change theatre (D-072).
    $user = User::factory()->create(['password' => 'current-password-here']);

    fakeSession($user, 'attacker-session');

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
    ])->assertNoContent();

    expect(DB::table('sessions')->where('id', 'attacker-session')->exists())->toBeFalse();
});

it('ends every session when an account is disabled', function (): void {
    // M1.5 refused a disabled account at login but left open sessions running.
    // Disabling somebody during an incident has to take effect now (D-074).
    $office = Office::factory()->create();

    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'users.disable', DataScope::OFFICE);

    $target = User::factory()->for($office)->create();
    fakeSession($target, 'target-session-one');
    fakeSession($target, 'target-session-two');

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/disable")
        ->assertOk();

    expect(DB::table('sessions')->where('user_id', $target->getKey())->count())->toBe(0);
});

it('leaves sessions alone when an account is enabled', function (): void {
    $office = Office::factory()->create();

    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'users.disable', DataScope::OFFICE);

    $target = User::factory()->for($office)->create(['is_active' => false]);
    fakeSession($target, 'target-session-one');

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/enable")
        ->assertOk();

    expect(DB::table('sessions')->where('id', 'target-session-one')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The registry itself
|--------------------------------------------------------------------------
*/

it('degrades to an empty list when the session driver cannot be enumerated', function (): void {
    // Honest rather than fabricated: an array-driven deployment has no readable
    // session store, and inventing rows would be inventing evidence.
    config(['session.driver' => 'array']);

    $user = User::factory()->create();

    expect(app(SessionRegistry::class)->forUser($user)->all())->toBe([])
        ->and(app(SessionRegistry::class)->revokeAll($user))->toBe(0)
        ->and(app(SessionRegistry::class)->revokeByKey($user, 'anything'))->toBeFalse();
});

it('produces a one-way opaque key', function (): void {
    $registry = app(SessionRegistry::class);

    expect($registry->opaqueKey('abc'))->toBe(hash('sha256', 'abc'))
        ->and($registry->opaqueKey('abc'))->toBe($registry->opaqueKey('abc'))
        ->and($registry->opaqueKey('abc'))->not->toBe($registry->opaqueKey('abd'));
});
