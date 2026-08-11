<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Models\Office;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Notification::fake();
    config(['session.driver' => 'database']);
    $this->withHeader('Origin', 'http://localhost');
});

/**
 * An actor holding one security capability at OFFICE scope, plus a colleague in
 * the same Office to act on.
 *
 * @return array{0: User, 1: User, 2: Office}
 */
function securityAdministrator(string $permission): array
{
    $office = Office::factory()->create();

    $actor = User::factory()->for($office)->create(['password' => 'actor-password-here']);
    grantPermissionScope($actor, $permission, DataScope::OFFICE);

    $target = User::factory()->for($office)->create(['password' => 'target-password-here']);

    return [$actor->fresh(), $target, $office];
}

/*
|--------------------------------------------------------------------------
| Administrator-triggered password reset
|--------------------------------------------------------------------------
*/

it('refuses a password reset to an actor without users.reset_password', function (): void {
    [$actor, $target] = securityAdministrator('users.update');

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/password-reset")
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('sends a reset link when the actor holds users.reset_password', function (): void {
    [$actor, $target] = securityAdministrator('users.reset_password');

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/password-reset")
        ->assertOk();

    Notification::assertSentTo($target, ResetPasswordLink::class);
});

it('never returns the reset token to the administrator', function (): void {
    // The whole design: an administrator may cause a mail to be sent to the
    // account owner, and learns nothing else (D-071).
    [$actor, $target] = securityAdministrator('users.reset_password');

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/password-reset")
        ->assertOk();

    $storedToken = DB::table('password_reset_tokens')->where('email', $target->email)->value('token');

    // The stored value is itself a hash of the emailed token, and even that
    // never appears. Neither does anything shaped like a credential.
    expect($response->getContent())->not->toContain($storedToken)
        ->and($response->json('data'))->toBe(['message' => 'A password reset link has been sent to the user.'])
        ->and($response->json('data'))->not->toHaveKey('token')
        ->and($response->json('data'))->not->toHaveKey('password');
});

it('does not change the password when a reset is triggered', function (): void {
    // Until the link is used, the existing password keeps working — an
    // administrator must not be able to lock somebody out by accident.
    [$actor, $target] = securityAdministrator('users.reset_password');

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/password-reset")
        ->assertOk();

    expect(Hash::check('target-password-here', $target->fresh()->password))->toBeTrue();
});

it('does not let an administrator choose the new password', function (): void {
    // There is no field for it. A submitted one is ignored rather than honoured.
    [$actor, $target] = securityAdministrator('users.reset_password');

    $this->actingAs($actor)->postJson("/api/v1/users/{$target->getKey()}/password-reset", [
        'password' => 'administrator-chosen-password',
        'password_confirmation' => 'administrator-chosen-password',
    ])->assertOk();

    expect(Hash::check('administrator-chosen-password', $target->fresh()->password))->toBeFalse()
        ->and(Hash::check('target-password-here', $target->fresh()->password))->toBeTrue();
});

it('refuses a password reset for a user outside the actor\'s Data Scope', function (): void {
    [$actor] = securityAdministrator('users.reset_password');

    $elsewhere = User::factory()->for(Office::factory()->create())->create();

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$elsewhere->getKey()}/password-reset")
        ->assertForbidden();

    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Completing a reset
|--------------------------------------------------------------------------
*/

it('completes a reset with a valid token and creates no session', function (): void {
    // No auto-login: an account with two-factor would otherwise have it bypassed
    // by a single emailed link (D-072).
    $user = User::factory()->create(['password' => 'old-password-here']);

    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/password-reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-x',
        'password_confirmation' => 'brand-new-password-x',
    ])->assertNoContent();

    expect(Hash::check('brand-new-password-x', $user->fresh()->password))->toBeTrue();

    $this->assertGuest();
});

it('refuses an invalid reset token', function (): void {
    $user = User::factory()->create(['password' => 'old-password-here']);

    $this->postJson('/password-reset', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'brand-new-password-x',
        'password_confirmation' => 'brand-new-password-x',
    ])->assertStatus(422);

    expect(Hash::check('old-password-here', $user->fresh()->password))->toBeTrue();
});

it('makes a reset token single use', function (): void {
    $user = User::factory()->create(['password' => 'old-password-here']);

    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/password-reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-x',
        'password_confirmation' => 'brand-new-password-x',
    ])->assertNoContent();

    $this->postJson('/password-reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'second-attempt-password',
        'password_confirmation' => 'second-attempt-password',
    ])->assertStatus(422);

    expect(Hash::check('brand-new-password-x', $user->fresh()->password))->toBeTrue();
});

it('revokes every session when a password is reset', function (): void {
    $user = User::factory()->create(['password' => 'old-password-here']);

    DB::table('sessions')->insert([
        'id' => 'stale-session',
        'user_id' => $user->getKey(),
        'ip_address' => '203.0.113.5',
        'user_agent' => 'Chrome',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/password-reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-x',
        'password_confirmation' => 'brand-new-password-x',
    ])->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $user->getKey())->count())->toBe(0);
});

it('preserves roles, office and two-factor across a reset', function (): void {
    // A password reset is not an account reset.
    $office = Office::factory()->create();
    $user = User::factory()->for($office)->create(['password' => 'old-password-here']);

    grantPermissionScope($user, 'projects.view', DataScope::OFFICE);
    [$secret] = enrolTwoFactor($user);

    $roles = $user->fresh()->getRoleNames()->sort()->values()->all();
    $token = app('auth.password.broker')->createToken($user->fresh());

    $this->postJson('/password-reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-x',
        'password_confirmation' => 'brand-new-password-x',
    ])->assertNoContent();

    $fresh = $user->fresh();

    expect($fresh->getRoleNames()->sort()->values()->all())->toBe($roles)
        ->and($fresh->office_id)->toBe($office->getKey())
        ->and($fresh->two_factor_secret)->toBe($secret)
        ->and($fresh->hasConfirmedTwoFactor())->toBeTrue();
});

it('still requires the second factor after a reset', function (): void {
    // The reason auto-login is refused, demonstrated end to end.
    $user = User::factory()->create(['password' => 'old-password-here']);
    enrolTwoFactor($user);

    $token = app('auth.password.broker')->createToken($user->fresh());

    $this->postJson('/password-reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-x',
        'password_confirmation' => 'brand-new-password-x',
    ])->assertNoContent();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'brand-new-password-x',
    ])->assertStatus(202);

    $this->assertGuest();
});

it('applies the same password rules to a reset as to a change', function (): void {
    $user = User::factory()->create(['password' => 'old-password-here']);

    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/password-reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

/*
|--------------------------------------------------------------------------
| Administrative session visibility and revocation
|--------------------------------------------------------------------------
*/

it('refuses session listing without security.sessions.view', function (): void {
    [$actor, $target] = securityAdministrator('users.view');

    $this->actingAs($actor)
        ->getJson("/api/v1/users/{$target->getKey()}/sessions")
        ->assertForbidden();
});

it('lists another user\'s sessions with security.sessions.view', function (): void {
    [$actor, $target] = securityAdministrator('security.sessions.view');

    DB::table('sessions')->insert([
        'id' => 'target-session',
        'user_id' => $target->getKey(),
        'ip_address' => '203.0.113.11',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Firefox/121.0',
        'payload' => base64_encode(serialize(['_token' => 'target-csrf-token'])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/users/{$target->getKey()}/sessions")
        ->assertOk();

    expect($response->json('data.*.key'))->toContain(hash('sha256', 'target-session'))
        ->and($response->getContent())->not->toContain('target-session')
        ->and($response->getContent())->not->toContain('target-csrf-token');
});

it('does not let session viewing imply session revocation', function (): void {
    // Looking and acting are separate decisions.
    [$actor, $target] = securityAdministrator('security.sessions.view');

    DB::table('sessions')->insert([
        'id' => 'target-session',
        'user_id' => $target->getKey(),
        'ip_address' => '203.0.113.11',
        'user_agent' => 'Chrome',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($actor)
        ->deleteJson("/api/v1/users/{$target->getKey()}/sessions")
        ->assertForbidden();

    expect(DB::table('sessions')->where('id', 'target-session')->exists())->toBeTrue();
});

it('revokes every session of another user with security.sessions.revoke', function (): void {
    [$actor, $target] = securityAdministrator('security.sessions.revoke');

    foreach (['t-one', 't-two'] as $id) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $target->getKey(),
            'ip_address' => '203.0.113.11',
            'user_agent' => 'Chrome',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    $this->actingAs($actor)
        ->deleteJson("/api/v1/users/{$target->getKey()}/sessions")
        ->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $target->getKey())->count())->toBe(0);
});

it('refuses session administration outside the actor\'s Data Scope', function (): void {
    [$actor] = securityAdministrator('security.sessions.revoke');

    $elsewhere = User::factory()->for(Office::factory()->create())->create();

    $this->actingAs($actor)->getJson("/api/v1/users/{$elsewhere->getKey()}/sessions")->assertForbidden();
    $this->actingAs($actor)->deleteJson("/api/v1/users/{$elsewhere->getKey()}/sessions")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Administrative two-factor removal
|--------------------------------------------------------------------------
*/

it('refuses two-factor removal without security.mfa.manage', function (): void {
    [$actor, $target] = securityAdministrator('users.update');
    enrolTwoFactor($target);

    $this->actingAs($actor)
        ->deleteJson("/api/v1/users/{$target->getKey()}/two-factor")
        ->assertForbidden();

    expect($target->fresh()->hasConfirmedTwoFactor())->toBeTrue();
});

it('removes another user\'s two-factor with security.mfa.manage', function (): void {
    // The recovery path for a lost phone and lost recovery codes.
    [$actor, $target] = securityAdministrator('security.mfa.manage');
    enrolTwoFactor($target);

    $this->actingAs($actor)
        ->deleteJson("/api/v1/users/{$target->getKey()}/two-factor", ['reason' => 'Lost device'])
        ->assertNoContent();

    $fresh = $target->fresh();

    expect($fresh->hasConfirmedTwoFactor())->toBeFalse()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull();
});

it('never lets an administrator read another user\'s two-factor secret', function (): void {
    // `manage` means remove, and only remove. There is no endpoint that reads a
    // secret, sets one, or issues recovery codes for somebody else (D-076).
    [$actor, $target] = securityAdministrator('security.mfa.manage');

    // Deliberately also given full read access to the target's record: the
    // point is that even an administrator entitled to see everything the API
    // offers about this person still cannot see their second factor.
    grantPermissionScope($actor, 'users.view', DataScope::OFFICE);
    $actor = $actor->fresh();

    [$secret, $codes] = enrolTwoFactor($target);

    foreach ([
        $this->actingAs($actor)->getJson("/api/v1/users/{$target->getKey()}"),
        $this->actingAs($actor)->getJson('/api/v1/users'),
    ] as $response) {
        expect($response->getContent())->not->toContain($secret)
            ->and($response->getContent())->not->toContain($codes[0])
            ->and($response->getContent())->not->toContain('two_factor_secret')
            ->and($response->getContent())->not->toContain('two_factor_recovery_codes');
    }
});

it('offers no endpoint for an administrator to set another user\'s two-factor', function (): void {
    [$actor, $target] = securityAdministrator('security.mfa.manage');

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/two-factor")
        ->assertStatus(405);

    $this->actingAs($actor)
        ->postJson("/api/v1/users/{$target->getKey()}/two-factor/recovery-codes")
        ->assertNotFound();
});

it('refuses two-factor removal outside the actor\'s Data Scope', function (): void {
    [$actor] = securityAdministrator('security.mfa.manage');

    $elsewhere = User::factory()->for(Office::factory()->create())->create();
    enrolTwoFactor($elsewhere);

    $this->actingAs($actor)
        ->deleteJson("/api/v1/users/{$elsewhere->getKey()}/two-factor")
        ->assertForbidden();

    expect($elsewhere->fresh()->hasConfirmedTwoFactor())->toBeTrue();
});

it('refuses every administrative security route to an unauthenticated caller', function (): void {
    $target = User::factory()->create();

    $this->postJson("/api/v1/users/{$target->getKey()}/password-reset")->assertUnauthorized();
    $this->getJson("/api/v1/users/{$target->getKey()}/sessions")->assertUnauthorized();
    $this->deleteJson("/api/v1/users/{$target->getKey()}/sessions")->assertUnauthorized();
    $this->deleteJson("/api/v1/users/{$target->getKey()}/two-factor")->assertUnauthorized();
});
