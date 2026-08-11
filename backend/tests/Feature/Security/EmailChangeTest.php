<?php

use App\Domains\Identity\Actions\RequestEmailChange;
use App\Models\User;
use App\Notifications\VerifyPendingEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    config(['session.driver' => 'database']);
    $this->withHeader('Origin', 'http://localhost');
});

/**
 * Request a change and return the raw token that was emailed.
 *
 * Read from the notification rather than the database, because the database only
 * ever holds the digest — which is exactly the property being relied on.
 */
function requestEmailChangeFor(User $user, string $newEmail): string
{
    app(RequestEmailChange::class)->handle($user, $newEmail);

    $token = null;

    Notification::assertSentOnDemand(
        VerifyPendingEmail::class,
        function (VerifyPendingEmail $notification, array $channels, object $notifiable) use (&$token, $newEmail): bool {
            if (($notifiable->routes['mail'] ?? null) !== $newEmail) {
                return false;
            }

            $reflection = new ReflectionProperty($notification, 'rawToken');
            $token = $reflection->getValue($notification);

            return true;
        },
    );

    return $token;
}

/*
|--------------------------------------------------------------------------
| Requesting a change
|--------------------------------------------------------------------------
*/

it('rejects an unauthenticated email change request', function (): void {
    $this->postJson('/api/v1/security/email', [
        'current_password' => 'x',
        'email' => 'new@example.test',
    ])->assertUnauthorized();
});

it('requires the current password to request an email change', function (): void {
    // The address is the login identifier, so changing it is a credential
    // change. A borrowed session must not be enough (D-073).
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)->postJson('/api/v1/security/email', [
        'current_password' => 'wrong-password',
        'email' => 'new@example.test',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');

    expect($user->fresh()->pending_email)->toBeNull();
});

it('does not change the current address when a change is requested', function (): void {
    // The old address stays authoritative until the new one proves it can
    // receive mail. A typo must never cost somebody their account.
    $user = User::factory()->create([
        'email' => 'original@example.test',
        'password' => 'current-password-here',
    ]);

    $this->actingAs($user)->postJson('/api/v1/security/email', [
        'current_password' => 'current-password-here',
        'email' => 'replacement@example.test',
    ])->assertOk();

    $fresh = $user->fresh();

    expect($fresh->email)->toBe('original@example.test')
        ->and($fresh->pending_email)->toBe('replacement@example.test');
});

it('sends the verification link to the new address, never the old one', function (): void {
    $user = User::factory()->create([
        'email' => 'original@example.test',
        'password' => 'current-password-here',
    ]);

    $this->actingAs($user)->postJson('/api/v1/security/email', [
        'current_password' => 'current-password-here',
        'email' => 'replacement@example.test',
    ])->assertOk();

    Notification::assertSentOnDemand(
        VerifyPendingEmail::class,
        fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'replacement@example.test',
    );

    Notification::assertNotSentTo($user, VerifyPendingEmail::class);
});

it('stores only a hash of the verification token', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $raw = requestEmailChangeFor($user, 'replacement@example.test');

    $stored = DB::table('users')->where('id', $user->getKey())->value('pending_email_token');

    expect($raw)->not->toBeEmpty()
        ->and($stored)->not->toBe($raw)
        ->and($stored)->toBe(hash('sha256', $raw));
});

it('refuses an email change to an address already in use', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);
    $other = User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($user)->postJson('/api/v1/security/email', [
        'current_password' => 'current-password-here',
        'email' => 'taken@example.test',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    expect($other->fresh()->email)->toBe('taken@example.test');
});

it('refuses an email change to the address already on the account', function (): void {
    $user = User::factory()->create([
        'email' => 'original@example.test',
        'password' => 'current-password-here',
    ]);

    $this->actingAs($user)->postJson('/api/v1/security/email', [
        'current_password' => 'current-password-here',
        'email' => 'original@example.test',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('replaces an earlier pending request when a new one is made', function (): void {
    // The correction path for a mistyped address: ask again.
    $user = User::factory()->create(['password' => 'current-password-here']);

    $firstToken = requestEmailChangeFor($user, 'typo@example.test');
    requestEmailChangeFor($user->fresh(), 'correct@example.test');

    expect($user->fresh()->pending_email)->toBe('correct@example.test');

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/email/verify', ['token' => $firstToken])
        ->assertStatus(422);

    expect($user->fresh()->email)->not->toBe('typo@example.test');
});

/*
|--------------------------------------------------------------------------
| Verifying a change
|--------------------------------------------------------------------------
*/

it('completes the change when the token is correct', function (): void {
    $user = User::factory()->create([
        'email' => 'original@example.test',
        'password' => 'current-password-here',
        'email_verified_at' => null,
    ]);

    $token = requestEmailChangeFor($user, 'replacement@example.test');

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/email/verify', ['token' => $token])
        ->assertOk();

    $fresh = $user->fresh();

    expect($fresh->email)->toBe('replacement@example.test')
        ->and($fresh->email_verified_at)->not->toBeNull()
        ->and($fresh->pending_email)->toBeNull()
        ->and($fresh->pending_email_token)->toBeNull()
        ->and($fresh->pending_email_requested_at)->toBeNull();
});

it('refuses a wrong verification token and changes nothing', function (): void {
    $user = User::factory()->create([
        'email' => 'original@example.test',
        'password' => 'current-password-here',
    ]);

    requestEmailChangeFor($user, 'replacement@example.test');

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/email/verify', ['token' => 'not-the-real-token'])
        ->assertStatus(422);

    $fresh = $user->fresh();

    expect($fresh->email)->toBe('original@example.test')
        ->and($fresh->pending_email)->toBe('replacement@example.test');
});

it('refuses an expired verification token', function (): void {
    $user = User::factory()->create([
        'email' => 'original@example.test',
        'password' => 'current-password-here',
    ]);

    $token = requestEmailChangeFor($user, 'replacement@example.test');

    $this->travel(RequestEmailChange::TTL_MINUTES + 1)->minutes();

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/email/verify', ['token' => $token])
        ->assertStatus(422);

    expect($user->fresh()->email)->toBe('original@example.test');
});

it('refuses verification when no change is pending', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)
        ->postJson('/api/v1/security/email/verify', ['token' => 'anything-at-all'])
        ->assertStatus(422);
});

it('refuses verification when the address was claimed in the meantime', function (): void {
    // Rechecked at verification rather than trusted from request time. Without
    // this, the unique constraint would surface as a 500 instead of a refusal.
    $user = User::factory()->create([
        'email' => 'original@example.test',
        'password' => 'current-password-here',
    ]);

    $token = requestEmailChangeFor($user, 'contested@example.test');

    User::factory()->create(['email' => 'contested@example.test']);

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/email/verify', ['token' => $token])
        ->assertStatus(422);

    expect($user->fresh()->email)->toBe('original@example.test');
});

it('makes the verification token single use', function (): void {
    $user = User::factory()->create([
        'email' => 'original@example.test',
        'password' => 'current-password-here',
    ]);

    $token = requestEmailChangeFor($user, 'replacement@example.test');

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/email/verify', ['token' => $token])
        ->assertOk();

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/email/verify', ['token' => $token])
        ->assertStatus(422);
});

it('cannot verify another user\'s pending email change', function (): void {
    $victim = User::factory()->create(['password' => 'current-password-here']);
    $attacker = User::factory()->create(['password' => 'attacker-password']);

    $token = requestEmailChangeFor($victim, 'victim-new@example.test');

    $this->actingAs($attacker)
        ->postJson('/api/v1/security/email/verify', ['token' => $token])
        ->assertStatus(422);

    expect($victim->fresh()->pending_email)->toBe('victim-new@example.test')
        ->and($attacker->fresh()->email)->not->toBe('victim-new@example.test');
});

it('revokes other sessions when the email changes', function (): void {
    // The address is a login identifier, so changing it is a credential change
    // and every other session loses its standing (D-073).
    $user = User::factory()->create([
        'email' => 'original@example.test',
        'password' => 'current-password-here',
    ]);

    $token = requestEmailChangeFor($user, 'replacement@example.test');

    DB::table('sessions')->insert([
        'id' => 'other-session',
        'user_id' => $user->getKey(),
        'ip_address' => '203.0.113.9',
        'user_agent' => 'Chrome',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($user->fresh())
        ->postJson('/api/v1/security/email/verify', ['token' => $token])
        ->assertOk();

    expect(DB::table('sessions')->where('id', 'other-session')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Cancelling, and what is never disclosed
|--------------------------------------------------------------------------
*/

it('cancels a pending email change', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    requestEmailChangeFor($user, 'replacement@example.test');

    $this->actingAs($user->fresh())->deleteJson('/api/v1/security/email')->assertOk();

    $fresh = $user->fresh();

    expect($fresh->pending_email)->toBeNull()
        ->and($fresh->pending_email_token)->toBeNull()
        ->and($fresh->pending_email_requested_at)->toBeNull();
});

it('never returns the pending email token from any endpoint', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $raw = requestEmailChangeFor($user, 'replacement@example.test');
    $digest = hash('sha256', $raw);

    foreach ([
        $this->actingAs($user->fresh())->getJson('/api/v1/security'),
        $this->actingAs($user->fresh())->getJson('/api/v1/profile'),
        $this->actingAs($user->fresh())->getJson('/api/v1/me'),
    ] as $response) {
        expect($response->getContent())->not->toContain($raw)
            ->and($response->getContent())->not->toContain($digest)
            ->and($response->getContent())->not->toContain('pending_email_token');
    }
});

it('hides the pending email token on the model itself', function (): void {
    // Two independent defences: the resources list attributes explicitly, and
    // the model hides the column so no dump or accidental serialization can
    // carry it (D-076).
    $user = User::factory()->create(['password' => 'current-password-here']);

    requestEmailChangeFor($user, 'replacement@example.test');

    expect($user->fresh()->toArray())->not->toHaveKey('pending_email_token');
});
