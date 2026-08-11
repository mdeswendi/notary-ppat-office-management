<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Changing your own password
|--------------------------------------------------------------------------
*/

it('rejects an unauthenticated password change', function (): void {
    $this->putJson('/api/v1/security/password', [
        'current_password' => 'secret-password',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertUnauthorized();
});

it('lets any authenticated user change their own password with no permission at all', function (): void {
    // Self-service, like the profile: authentication plus self-ownership is the
    // whole boundary. Requiring a `security.*` permission would let a user be
    // forbidden from securing their own account (D-071).
    $user = User::factory()->create(['password' => 'current-password-here']);

    expect($user->getAllPermissions())->toBeEmpty();

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
    ])->assertNoContent();

    expect(Hash::check('replacement-password-x', $user->fresh()->password))->toBeTrue();
});

it('refuses a password change without the current password', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');

    expect(Hash::check('current-password-here', $user->fresh()->password))->toBeTrue();
});

it('refuses a password change when the current password is wrong', function (): void {
    // A live session is not proof of identity — an unattended screen is a live
    // session too.
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'not-the-right-one',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');

    expect(Hash::check('current-password-here', $user->fresh()->password))->toBeTrue();
});

it('refuses a password change when the confirmation does not match', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-y',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('refuses a new password that is the same as the current one', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'current-password-here',
        'password_confirmation' => 'current-password-here',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('refuses a password that is too short', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('never returns the password in the response body', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $response = $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
    ]);

    expect($response->getContent())->not->toContain('replacement-password-x')
        ->and($response->getContent())->not->toContain('current-password-here')
        ->and($response->getContent())->toBe('');
});

it('stores the new password hashed, never in plaintext', function (): void {
    $user = User::factory()->create(['password' => 'current-password-here']);

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
    ])->assertNoContent();

    $stored = $user->fresh()->password;

    expect($stored)->not->toBe('replacement-password-x')
        ->and($stored)->toStartWith('$')
        ->and(Hash::check('replacement-password-x', $stored))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| What a password change must not disturb
|--------------------------------------------------------------------------
*/

it('preserves roles, office, profile and locale across a password change', function (): void {
    $office = Office::factory()->create();

    $user = User::factory()->for($office)->create([
        'password' => 'current-password-here',
        'name' => 'Rahmat Hidayat',
        'phone' => '0812-0000-0000',
        'preferred_locale' => 'en',
    ]);

    grantPermissionScope($user, 'projects.view', DataScope::OFFICE);

    $before = [
        'roles' => $user->getRoleNames()->sort()->values()->all(),
        'office_id' => $user->office_id,
        'name' => $user->name,
        'phone' => $user->phone,
        'locale' => $user->preferred_locale,
    ];

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
    ])->assertNoContent();

    $fresh = $user->fresh();

    expect($fresh->getRoleNames()->sort()->values()->all())->toBe($before['roles'])
        ->and($fresh->office_id)->toBe($before['office_id'])
        ->and($fresh->name)->toBe($before['name'])
        ->and($fresh->phone)->toBe($before['phone'])
        ->and($fresh->preferred_locale)->toBe($before['locale']);
});

it('preserves two-factor configuration across a password change', function (): void {
    // A password change is not an account reset. Clearing the second factor here
    // would quietly downgrade the account every time somebody rotated a password.
    $user = User::factory()->create(['password' => 'current-password-here']);

    $user->forceFill([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['hash-a', 'hash-b'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
    ])->assertNoContent();

    $fresh = $user->fresh();

    expect($fresh->hasConfirmedTwoFactor())->toBeTrue()
        ->and($fresh->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP')
        ->and($fresh->two_factor_recovery_codes)->toBe(['hash-a', 'hash-b']);
});

it('cannot change another user\'s password', function (): void {
    // There is no id parameter anywhere on this route, so the attempt has no
    // shape to take. That is the design, not an omission.
    $user = User::factory()->create(['password' => 'current-password-here']);
    $other = User::factory()->create(['password' => 'other-password-here']);

    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
        'user_id' => $other->getKey(),
        'email' => $other->email,
    ])->assertNoContent();

    expect(Hash::check('other-password-here', $other->fresh()->password))->toBeTrue()
        ->and(Hash::check('replacement-password-x', $user->fresh()->password))->toBeTrue();
});
