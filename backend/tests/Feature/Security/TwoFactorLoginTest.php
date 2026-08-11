<?php

use App\Domains\Identity\Actions\DisableTwoFactor;
use App\Domains\Identity\TwoFactor;
use App\Domains\Identity\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('two-factor|test');
    config(['session.driver' => 'database']);
});

/*
|--------------------------------------------------------------------------
| The password step
|--------------------------------------------------------------------------
*/

it('logs in with the password alone when two-factor is not enabled', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertNoContent();

    $this->assertAuthenticatedAs($user);
});

it('does not create a session when two-factor is enabled', function (): void {
    // The critical property. Logging the user in first and "requiring" the code
    // afterwards would leave a real session any client could simply use by
    // ignoring the response (D-075).
    $user = User::factory()->create(['password' => 'current-password-here']);
    [$secret] = enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202)->assertJson(['two_factor' => true]);

    $this->assertGuest();
});

it('cannot reach an authenticated endpoint after only the password step', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->getJson('/api/v1/security')->assertUnauthorized();
});

it('never reveals the two-factor secret in the login response', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    [$secret] = enrolTwoFactor($user);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ]);

    expect($response->getContent())->not->toContain($secret);
});

it('does not stamp last_login_at until the second factor is supplied', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here', 'last_login_at' => null]);
    enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    expect($user->fresh()->last_login_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The challenge step
|--------------------------------------------------------------------------
*/

it('completes the login when the authenticator code verifies', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    [$secret] = enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    $this->post('/login/two-factor-challenge', ['code' => totpFor($secret)])->assertNoContent();

    $this->assertAuthenticatedAs($user->fresh());
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('refuses a wrong code and leaves the caller unauthenticated', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    $this->postJson('/login/two-factor-challenge', ['code' => '000000'])->assertStatus(422);

    $this->assertGuest();
});

it('cannot be called without first passing the password step', function (): void {
    // Not an alternative way in: it accepts no email and no user id, so it has
    // nothing to act on without a live challenge in the session.
    $user = User::factory()->create(['password' => 'current-password-here']);
    [$secret] = enrolTwoFactor($user);

    $this->postJson('/login/two-factor-challenge', ['code' => totpFor($secret)])->assertStatus(422);

    $this->assertGuest();
});

it('refuses a challenge that has expired', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    [$secret] = enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    $this->travel(TwoFactorChallenge::TTL_MINUTES + 1)->minutes();

    $this->postJson('/login/two-factor-challenge', ['code' => totpFor($secret)])->assertStatus(422);

    $this->assertGuest();
});

it('voids a challenge if the account is disabled in the meantime', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    [$secret] = enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    $user->forceFill(['is_active' => false])->save();

    $this->postJson('/login/two-factor-challenge', ['code' => totpFor($secret)])->assertStatus(422);

    $this->assertGuest();
});

it('rejects a challenge submitted with neither a code nor a recovery code', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    $this->postJson('/login/two-factor-challenge', [])->assertStatus(422);

    $this->assertGuest();
});

it('never echoes the submitted code back in an error', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    $response = $this->postJson('/login/two-factor-challenge', ['code' => '424242']);

    expect($response->getContent())->not->toContain('424242');
});

/*
|--------------------------------------------------------------------------
| Recovery codes
|--------------------------------------------------------------------------
*/

it('accepts a recovery code in place of an authenticator code', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    [, $codes] = enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    $this->post('/login/two-factor-challenge', ['recovery_code' => $codes[0]])->assertNoContent();

    $this->assertAuthenticatedAs($user->fresh());
});

it('consumes a recovery code so it cannot be used twice', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    [, $codes] = enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);
    $this->post('/login/two-factor-challenge', ['recovery_code' => $codes[0]])->assertNoContent();

    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(TwoFactor::RECOVERY_CODE_COUNT - 1);

    $this->post('/logout')->assertNoContent();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);
    $this->postJson('/login/two-factor-challenge', ['recovery_code' => $codes[0]])->assertStatus(422);

    $this->assertGuest();
});

it('leaves the remaining recovery codes usable', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    [, $codes] = enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);
    $this->post('/login/two-factor-challenge', ['recovery_code' => $codes[0]])->assertNoContent();
    $this->post('/logout')->assertNoContent();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);
    $this->post('/login/two-factor-challenge', ['recovery_code' => $codes[1]])->assertNoContent();

    $this->assertAuthenticatedAs($user->fresh());
});

it('hashes recovery codes so a database read cannot supply one', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    [, $codes] = enrolTwoFactor($user);

    $stored = $user->fresh()->two_factor_recovery_codes;

    foreach ($stored as $hash) {
        expect($hash)->not->toBe($codes[0]);
    }

    expect(Hash::check($codes[0], $stored[0]))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
*/

it('throttles repeated wrong codes', function (): void {
    // Six digits is a million possibilities, which is plenty against a person
    // and nothing against a script. The rate limit carries this endpoint.
    $user = User::factory()->create(['password' => 'current-password-here']);
    enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/login/two-factor-challenge', ['code' => '000000'])->assertStatus(422);
    }

    $this->postJson('/login/two-factor-challenge', ['code' => '000000'])->assertStatus(429);

    $this->assertGuest();
});

it('throttles a correct code once the limit is reached', function (): void {
    // The limit is not bypassed by finally guessing right.
    $user = User::factory()->create(['password' => 'current-password-here']);
    [$secret] = enrolTwoFactor($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(202);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/login/two-factor-challenge', ['code' => '000000'])->assertStatus(422);
    }

    $this->postJson('/login/two-factor-challenge', ['code' => totpFor($secret)])->assertStatus(429);

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Interaction with the rest of the account
|--------------------------------------------------------------------------
*/

it('still refuses a disabled account at the password step', function (): void {
    // `is_active` remains part of the credential lookup, so a disabled account
    // never even reaches the challenge.
    $user = User::factory()->create(['password' => 'current-password-here', 'is_active' => false]);
    enrolTwoFactor($user);

    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertStatus(422);

    $this->assertGuest();
});

it('does not require a second factor after the administrator removes it', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    enrolTwoFactor($user);

    app(DisableTwoFactor::class)->handle($user->fresh());

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertNoContent();

    $this->assertAuthenticatedAs($user->fresh());
});
