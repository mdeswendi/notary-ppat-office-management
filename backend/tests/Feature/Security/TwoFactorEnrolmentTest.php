<?php

use App\Domains\Identity\TwoFactor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['session.driver' => 'database']);
    $this->withHeader('Origin', 'http://localhost');
});

/*
|--------------------------------------------------------------------------
| Starting enrolment
|--------------------------------------------------------------------------
*/

it('rejects unauthenticated two-factor enrolment', function (): void {
    $this->postJson('/api/v1/security/two-factor')->assertUnauthorized();
});

it('lets any authenticated user enrol with no permission at all', function (): void {
    $user = User::factory()->create();

    expect($user->getAllPermissions())->toBeEmpty();

    $this->actingAs($user)->postJson('/api/v1/security/two-factor')
        ->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'provisioning_uri', 'qr_code_svg']]);
});

it('does not require a second factor at login until enrolment is confirmed', function (): void {
    // Somebody who closed the setup dialog must not be locked out of their own
    // account (D-076).
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)->postJson('/api/v1/security/two-factor')->assertOk();

    $fresh = $user->fresh();

    expect($fresh->two_factor_secret)->not->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull()
        ->and($fresh->hasConfirmedTwoFactor())->toBeFalse();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'current-password-here',
    ])->assertNoContent();

    $this->assertAuthenticatedAs($user->fresh());
});

it('issues no recovery codes before confirmation', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/security/two-factor');

    expect($response->json('data'))->not->toHaveKey('recovery_codes')
        ->and($user->fresh()->two_factor_recovery_codes)->toBeNull();
});

it('replaces an unconfirmed secret when enrolment is restarted', function (): void {
    $user = User::factory()->create();

    $first = $this->actingAs($user)->postJson('/api/v1/security/two-factor')->json('data.secret');
    $second = $this->actingAs($user->fresh())->postJson('/api/v1/security/two-factor')->json('data.secret');

    expect($second)->not->toBe($first)
        ->and($user->fresh()->two_factor_secret)->toBe($second);
});

it('refuses to restart enrolment once two-factor is confirmed', function (): void {
    // A new secret would break the authenticator that currently works.
    $user = User::factory()->create();
    [$secret] = enrolTwoFactor($user);

    $this->actingAs($user->fresh())->postJson('/api/v1/security/two-factor')->assertStatus(422);

    expect($user->fresh()->two_factor_secret)->toBe($secret);
});

it('expires an abandoned enrolment', function (): void {
    $user = User::factory()->create();

    $secret = $this->actingAs($user)->postJson('/api/v1/security/two-factor')->json('data.secret');

    $this->travel(TwoFactor::SETUP_TTL_MINUTES + 1)->minutes();

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/two-factor/confirm', ['code' => totpFor($secret)])
        ->assertStatus(422);

    expect($user->fresh()->hasConfirmedTwoFactor())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Confirming enrolment
|--------------------------------------------------------------------------
*/

it('enables two-factor when the first code verifies', function (): void {
    $user = User::factory()->create();

    $secret = $this->actingAs($user)->postJson('/api/v1/security/two-factor')->json('data.secret');

    $response = $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/two-factor/confirm', ['code' => totpFor($secret)])
        ->assertOk();

    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue()
        ->and($response->json('data.recovery_codes'))->toHaveCount(TwoFactor::RECOVERY_CODE_COUNT);
});

it('refuses a wrong confirmation code and keeps the pending enrolment', function (): void {
    // A clock a few seconds out should cost a retry, not the whole enrolment.
    $user = User::factory()->create();

    $secret = $this->actingAs($user)->postJson('/api/v1/security/two-factor')->json('data.secret');

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/two-factor/confirm', ['code' => '000000'])
        ->assertStatus(422);

    $fresh = $user->fresh();

    expect($fresh->hasConfirmedTwoFactor())->toBeFalse()
        ->and($fresh->two_factor_secret)->toBe($secret)
        ->and($fresh->hasPendingTwoFactorSetup())->toBeTrue();
});

it('refuses confirmation when no enrolment was started', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/security/two-factor/confirm', ['code' => '123456'])
        ->assertStatus(422);
});

it('rejects a confirmation code that is not six digits', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/security/two-factor')->assertOk();

    foreach (['12345', '1234567', 'abcdef', ''] as $bad) {
        $this->actingAs($user->fresh())
            ->postJson('/api/v1/security/two-factor/confirm', ['code' => $bad])
            ->assertStatus(422);
    }
});

it('stores recovery codes hashed, never in plaintext', function (): void {
    $user = User::factory()->create();

    $secret = $this->actingAs($user)->postJson('/api/v1/security/two-factor')->json('data.secret');

    $codes = $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/two-factor/confirm', ['code' => totpFor($secret)])
        ->json('data.recovery_codes');

    $stored = $user->fresh()->two_factor_recovery_codes;

    expect($stored)->toHaveCount(TwoFactor::RECOVERY_CODE_COUNT)
        ->and($stored)->not->toContain($codes[0])
        ->and(Hash::check($codes[0], $stored[0]))->toBeTrue();
});

it('encrypts the two-factor secret at rest', function (): void {
    // A database dump must not hand over the ability to mint valid codes.
    $user = User::factory()->create();

    $secret = $this->actingAs($user)->postJson('/api/v1/security/two-factor')->json('data.secret');

    $raw = DB::table('users')->where('id', $user->getKey())->value('two_factor_secret');

    expect($raw)->not->toBe($secret)
        ->and($raw)->not->toContain($secret)
        ->and($user->fresh()->two_factor_secret)->toBe($secret);
});

it('encrypts the recovery codes at rest', function (): void {
    $user = User::factory()->create();
    [, $codes] = enrolTwoFactor($user);

    $raw = DB::table('users')->where('id', $user->getKey())->value('two_factor_recovery_codes');

    expect($raw)->not->toContain($codes[0])
        ->and($raw)->not->toContain('$2y$');
});

/*
|--------------------------------------------------------------------------
| What is never readable afterwards
|--------------------------------------------------------------------------
*/

it('never exposes the two-factor secret from any read endpoint', function (): void {
    $user = User::factory()->create();
    [$secret] = enrolTwoFactor($user);

    foreach ([
        $this->actingAs($user->fresh())->getJson('/api/v1/security'),
        $this->actingAs($user->fresh())->getJson('/api/v1/profile'),
        $this->actingAs($user->fresh())->getJson('/api/v1/me'),
    ] as $response) {
        expect($response->getContent())->not->toContain($secret)
            ->and($response->getContent())->not->toContain('two_factor_secret');
    }
});

it('never exposes recovery codes after they are issued', function (): void {
    // Not even to the user who owns them. A code readable after the fact is a
    // second password sitting in the database (D-076).
    $user = User::factory()->create();
    [, $codes] = enrolTwoFactor($user);

    $response = $this->actingAs($user->fresh())->getJson('/api/v1/security');

    expect($response->getContent())->not->toContain($codes[0])
        ->and($response->getContent())->not->toContain('two_factor_recovery_codes')
        ->and($response->json('data.recovery_codes_remaining'))->toBe(TwoFactor::RECOVERY_CODE_COUNT);
});

it('hides the two-factor columns on the model itself', function (): void {
    $user = User::factory()->create();
    enrolTwoFactor($user);

    $array = $user->fresh()->toArray();

    expect($array)->not->toHaveKey('two_factor_secret')
        ->and($array)->not->toHaveKey('two_factor_recovery_codes');
});

/*
|--------------------------------------------------------------------------
| Disabling and regenerating
|--------------------------------------------------------------------------
*/

it('requires the current password to disable two-factor', function (): void {
    // Removing protection is where friction belongs.
    $user = User::factory()->create(['password' => 'current-password-here']);
    enrolTwoFactor($user);

    $this->actingAs($user->fresh())
        ->deleteJson('/api/v1/security/two-factor', ['current_password' => 'wrong-password'])
        ->assertStatus(422);

    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();
});

it('disables two-factor and clears every trace of it', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    enrolTwoFactor($user);

    $this->actingAs($user->fresh())
        ->deleteJson('/api/v1/security/two-factor', ['current_password' => 'current-password-here'])
        ->assertNoContent();

    $fresh = $user->fresh();

    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull()
        ->and($fresh->two_factor_setup_expires_at)->toBeNull();
});

it('refuses to disable two-factor that is not enabled', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)
        ->deleteJson('/api/v1/security/two-factor', ['current_password' => 'current-password-here'])
        ->assertStatus(422);
});

it('requires the current password to regenerate recovery codes', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    [, $codes] = enrolTwoFactor($user);

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/two-factor/recovery-codes', ['current_password' => 'wrong-password'])
        ->assertStatus(422);

    expect(Hash::check($codes[0], $user->fresh()->two_factor_recovery_codes[0]))->toBeTrue();
});

it('replaces every recovery code on regeneration', function (): void {
    // Total replacement, not a top-up: somebody regenerating has decided the old
    // list is compromised, and one surviving code keeps the hole open.
    $user = User::factory()->create(['password' => 'current-password-here']);
    [, $original] = enrolTwoFactor($user);

    $fresh = $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/two-factor/recovery-codes', ['current_password' => 'current-password-here'])
        ->assertOk()
        ->json('data.recovery_codes');

    expect($fresh)->toHaveCount(TwoFactor::RECOVERY_CODE_COUNT)
        ->and(array_intersect($fresh, $original))->toBe([]);

    foreach ($user->fresh()->two_factor_recovery_codes as $hash) {
        foreach ($original as $old) {
            expect(Hash::check($old, $hash))->toBeFalse();
        }
    }
});

it('refuses to regenerate recovery codes when two-factor is not enabled', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)
        ->postJson('/api/v1/security/two-factor/recovery-codes', ['current_password' => 'current-password-here'])
        ->assertStatus(422);
});
