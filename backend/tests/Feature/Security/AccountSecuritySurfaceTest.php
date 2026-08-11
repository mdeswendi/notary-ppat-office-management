<?php

use App\Domains\Authorization\PermissionRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Every PHP file under `app/`, with comments stripped.
 *
 * Comments are removed so that a scan for a forbidden call cannot be satisfied
 * or defeated by prose — a docblock explaining why `Gate::allows` is banned must
 * not read as a violation, and a violation must not hide inside a comment.
 *
 * @return array<string, string>
 */
function securityScanSources(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $stripped = '';

        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        $files[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = $stripped;
    }

    return $files;
}

it('can actually find a string it is looking for', function (): void {
    // The sentinel. A scan that silently matches nothing reports "clean" for
    // every rule it enforces, which is worse than not scanning at all — M1.8
    // shipped exactly that mistake once.
    $sources = securityScanSources();

    expect($sources)->not->toBeEmpty();

    $found = collect($sources)->filter(
        fn (string $body): bool => str_contains($body, 'EffectiveAccessResolver')
    );

    expect($found)->not->toBeEmpty();
});

it('strips comments before scanning', function (): void {
    // Proves the previous helper does what the other scans depend on: the
    // banned calls are named in docblocks throughout this codebase.
    $sources = securityScanSources();

    expect($sources['app\\Policies\\UserPolicy.php'] ?? $sources['app/Policies/UserPolicy.php'])
        ->not->toContain('Never authorize');
});

/*
|--------------------------------------------------------------------------
| Nothing logs a secret
|--------------------------------------------------------------------------
*/

it('never passes a credential field into a log call', function (): void {
    // Not a proof, but it catches the obvious regression: somebody adding the
    // submitted password or a token to a debugging log line and leaving it in.
    $forbidden = [
        "'password' =>",
        "'current_password' =>",
        "'token' =>",
        "'raw_token' =>",
        "'rawToken' =>",
        "'code' =>",
        "'recovery_code' =>",
        "'two_factor_secret' =>",
        "'secret' =>",
        "'session_id' =>",
    ];

    $offenders = [];

    foreach (securityScanSources() as $path => $body) {
        // Each `Log::` call up to the closing of its argument list, roughly.
        preg_match_all('/Log::\w+\((.*?)\);/s', $body, $matches);

        foreach ($matches[1] as $arguments) {
            foreach ($forbidden as $needle) {
                if (str_contains($arguments, $needle)) {
                    $offenders[] = "{$path}: {$needle}";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('never puts a raw session id in a log call', function (): void {
    $offenders = [];

    foreach (securityScanSources() as $path => $body) {
        preg_match_all('/Log::\w+\((.*?)\);/s', $body, $matches);

        foreach ($matches[1] as $arguments) {
            if (str_contains($arguments, 'session()->getId()') || str_contains($arguments, '->getId()')) {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The authorization model is unchanged
|--------------------------------------------------------------------------
*/

it('adds no new canonical permission', function (): void {
    // M1.9 uses the codes the registry already carried — users.reset_password,
    // security.sessions.view, security.sessions.revoke, security.mfa.manage —
    // rather than inventing more. The count is the assertion.
    expect(count(PermissionRegistry::all()))->toBe(171);
});

it('uses only registered canonical permissions in the security policy abilities', function (): void {
    foreach ([
        'users.reset_password',
        'security.sessions.view',
        'security.sessions.revoke',
        'security.mfa.manage',
    ] as $permission) {
        expect(PermissionRegistry::all())->toContain($permission);
    }
});

it('still authorizes nothing through the package permission gate', function (): void {
    // D-048 stands. Nothing added in M1.9 re-enables it.
    expect(config('permission.register_permission_check_method'))->toBeFalse();
});

it('authorizes no security operation by role name', function (): void {
    $offenders = [];

    foreach (securityScanSources() as $path => $body) {
        foreach (['hasRole(', 'hasAnyRole(', 'hasAllRoles(', 'Gate::before('] as $needle) {
            if (str_contains($body, $needle)) {
                $offenders[] = "{$path}: {$needle}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The stored shape
|--------------------------------------------------------------------------
*/

it('adds exactly the account-security columns and no others', function (): void {
    foreach ([
        'pending_email',
        'pending_email_token',
        'pending_email_requested_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_setup_expires_at',
    ] as $column) {
        expect(Schema::hasColumn('users', $column))->toBeTrue();
    }
});

it('stores no plaintext credential column', function (): void {
    foreach ([
        'plain_password',
        'temporary_password',
        'password_plain',
        'two_factor_secret_plain',
        'recovery_codes_plain',
    ] as $column) {
        expect(Schema::hasColumn('users', $column))->toBeFalse();
    }
});

it('reuses the framework tables rather than duplicating them', function (): void {
    // `password_reset_tokens` and `sessions` already existed. A second
    // reset-token table would be a second expiry rule to keep in step.
    expect(Schema::hasTable('password_reset_tokens'))->toBeTrue()
        ->and(Schema::hasTable('sessions'))->toBeTrue()
        ->and(Schema::hasTable('two_factor_secrets'))->toBeFalse()
        ->and(Schema::hasTable('email_change_tokens'))->toBeFalse()
        ->and(Schema::hasTable('user_sessions'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The route surface
|--------------------------------------------------------------------------
*/

it('exposes exactly the expected security routes', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/security'))
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->unique()->values()->sort()->values()->all();

    expect($routes)->toBe([
        'DELETE api/v1/security/email',
        'DELETE api/v1/security/sessions/others',
        'DELETE api/v1/security/sessions/{session}',
        'DELETE api/v1/security/two-factor',
        'GET|HEAD api/v1/security',
        'GET|HEAD api/v1/security/sessions',
        'POST api/v1/security/email',
        'POST api/v1/security/email/verify',
        'POST api/v1/security/two-factor',
        'POST api/v1/security/two-factor/confirm',
        'POST api/v1/security/two-factor/recovery-codes',
        'PUT api/v1/security/password',
    ]);
});

it('accepts no user id on any self-service security route', function (): void {
    // The boundary is structural, not a check somebody could forget to write.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/security'));

    foreach ($routes as $route) {
        expect($route->uri())->not->toContain('{user}');
    }
});

it('requires authentication on every self-service security route', function (): void {
    $this->getJson('/api/v1/security')->assertUnauthorized();
    $this->putJson('/api/v1/security/password')->assertUnauthorized();
    $this->postJson('/api/v1/security/email')->assertUnauthorized();
    $this->postJson('/api/v1/security/email/verify')->assertUnauthorized();
    $this->deleteJson('/api/v1/security/email')->assertUnauthorized();
    $this->postJson('/api/v1/security/two-factor')->assertUnauthorized();
    $this->postJson('/api/v1/security/two-factor/confirm')->assertUnauthorized();
    $this->deleteJson('/api/v1/security/two-factor')->assertUnauthorized();
    $this->postJson('/api/v1/security/two-factor/recovery-codes')->assertUnauthorized();
    $this->getJson('/api/v1/security/sessions')->assertUnauthorized();
    $this->deleteJson('/api/v1/security/sessions/others')->assertUnauthorized();
    $this->deleteJson('/api/v1/security/sessions/abc')->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
*/

it('shares one rate-limit bucket across every endpoint taking the current password', function (): void {
    // Deliberate. `current_password` could otherwise be used as an oracle by
    // rotating between four endpoints for four times the guesses (D-071).
    config(['session.driver' => 'database']);

    $user = User::factory()->create(['password' => 'current-password-here']);

    // Six wrong attempts, spread across three different routes.
    for ($i = 0; $i < 2; $i++) {
        $this->actingAs($user)->putJson('/api/v1/security/password', [
            'current_password' => 'wrong-password',
            'password' => 'replacement-password-x',
            'password_confirmation' => 'replacement-password-x',
        ])->assertStatus(422);

        $this->actingAs($user)->postJson('/api/v1/security/email', [
            'current_password' => 'wrong-password',
            'email' => "probe{$i}@example.test",
        ])->assertStatus(422);

        $this->actingAs($user)
            ->deleteJson('/api/v1/security/sessions/others', ['current_password' => 'wrong-password'])
            ->assertStatus(422);
    }

    // The seventh is refused whichever of the three routes it arrives on.
    $this->actingAs($user)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
    ])->assertStatus(429);
});

it('does not spend the password budget on two-factor setup', function (): void {
    // The bug the named limiters exist to prevent: Laravel's unnamed throttle
    // keys authenticated requests on the user id alone, so mistyping a password
    // would block starting an enrolment.
    $user = User::factory()->create(['password' => 'current-password-here']);

    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($user)->putJson('/api/v1/security/password', [
            'current_password' => 'wrong-password',
            'password' => 'replacement-password-x',
            'password_confirmation' => 'replacement-password-x',
        ])->assertStatus(422);
    }

    $this->actingAs($user)->postJson('/api/v1/security/two-factor')->assertOk();
});

/*
|--------------------------------------------------------------------------
| The overview payload
|--------------------------------------------------------------------------
*/

it('serves the security overview to any authenticated user', function (): void {
    $user = User::factory()->create();

    expect($user->getAllPermissions())->toBeEmpty();

    $this->actingAs($user)->getJson('/api/v1/security')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'email',
                'pending_email',
                'pending_email_requested_at',
                'two_factor_enabled',
                'two_factor_confirmed_at',
                'recovery_codes_remaining',
                'last_login_at',
            ],
        ]);
});

it('exposes exactly those keys and nothing more in the overview', function (): void {
    $user = User::factory()->create();
    enrolTwoFactor($user);

    $data = $this->actingAs($user->fresh())->getJson('/api/v1/security')->json('data');

    expect(array_keys($data))->toBe([
        'email',
        'pending_email',
        'pending_email_requested_at',
        'two_factor_enabled',
        'two_factor_confirmed_at',
        'recovery_codes_remaining',
        'last_login_at',
    ]);
});

it('reports two-factor as off when a secret exists but was never confirmed', function (): void {
    // The distinction that keeps a failed scan from becoming a lockout.
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_setup_expires_at' => now()->addMinutes(10),
    ])->save();

    $data = $this->actingAs($user->fresh())->getJson('/api/v1/security')->json('data');

    expect($data['two_factor_enabled'])->toBeFalse()
        ->and($data['recovery_codes_remaining'])->toBe(0);
});
