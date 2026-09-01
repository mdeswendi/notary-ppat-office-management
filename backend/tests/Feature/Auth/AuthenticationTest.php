<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The throttle key is derived from email plus IP, so it survives between
    // tests unless cleared. Each test starts from a clean limiter.
    RateLimiter::clear(strtolower('user@example.test').'|127.0.0.1');
});

it('exposes the csrf cookie endpoint', function (): void {
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

it('rejects an anonymous request to the current user endpoint', function (): void {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('rejects an anonymous request that does not ask for json', function (): void {
    // A protected API URL opened straight in the address bar, which sends an
    // HTML Accept header and no XMLHttpRequest marker. That path skips the
    // JSON branch in the Authenticate middleware and used to reach the
    // framework's default guest redirect, whose route does not exist here — so
    // it answered 500 and disclosed a stack trace instead of 401. See the
    // redirectGuestsTo note in bootstrap/app.php.
    $this->get('/api/v1/me', ['Accept' => 'text/html,application/xhtml+xml'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('logs in with valid credentials', function (): void {
    $user = User::factory()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ])->assertNoContent();

    $this->assertAuthenticatedAs($user);
});

it('returns the current user for a session authenticated request', function (): void {
    $user = User::factory()->create([
        'name' => 'Rina',
        'email' => 'user@example.test',
        'preferred_locale' => 'id',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $user->id,
                'name' => 'Rina',
                'email' => 'user@example.test',
                'preferred_locale' => 'id',
                // Present since M0.8. Empty here because this user holds no
                // assignments; AuthorizationTest covers populated cases.
                'roles' => [],
                // Effective access since M1.7, not Spatie's raw grant list
                // (D-062). Scopes travel with the permissions they qualify.
                'permissions' => [],
                'permission_scopes' => [],
            ],
        ]);
});

it('keeps the session alive across a second request', function (): void {
    $user = User::factory()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ])->assertNoContent();

    // Two separate requests on the same session, standing in for a page
    // refresh: the second must still be authenticated.
    $this->getJson('/api/v1/me')->assertOk();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
});

it('rejects invalid credentials without revealing which field was wrong', function (): void {
    User::factory()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    $response = $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'wrong-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect($response->json('errors.email.0'))
        ->toBe('These credentials do not match our records.');

    $this->assertGuest();
});

it('gives the same generic message for an unknown account', function (): void {
    $response = $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'whatever',
    ])->assertUnprocessable();

    // Identical to the wrong-password message, so responses cannot be used
    // to discover which addresses have accounts.
    expect($response->json('errors.email.0'))
        ->toBe('These credentials do not match our records.');
});

it('refuses to log in an inactive user', function (): void {
    User::factory()->inactive()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->assertGuest();
});

it('validates that email and password are present', function (): void {
    $this->postJson('/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

it('logs out and ends authentication', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/logout')
        ->assertNoContent();

    $this->assertGuest();
});

it('rejects the current user endpoint after logout', function (): void {
    User::factory()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    // Deliberately the real session flow rather than actingAs(): that helper
    // pins the user onto the guard instance for the whole test, so it would
    // keep authenticating requests after logout and hide the regression this
    // test exists to catch.
    $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ])->assertNoContent();

    $this->getJson('/api/v1/me')->assertOk();

    $this->postJson('/logout')->assertNoContent();

    // Every real HTTP request builds a fresh application, so a guard can never
    // carry a resolved user across the boundary. In-process tests reuse one
    // container, and the earlier /me call left the authenticated user cached
    // on the sanctum guard. Forgetting the guards reproduces the real
    // boundary; without it this assertion would pass against stale state.
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('throttles repeated failed logins with a 429', function (): void {
    User::factory()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    // Five failures are allowed; the sixth attempt is throttled.
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/login', [
            'email' => 'user@example.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'wrong-password',
    ])->assertStatus(429);
});

it('throttles even when the correct password is finally supplied', function (): void {
    User::factory()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/login', [
            'email' => 'user@example.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ])->assertStatus(429);

    $this->assertGuest();
});

it('clears the throttle counter after a successful login', function (): void {
    User::factory()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/login', [
            'email' => 'user@example.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ])->assertNoContent();

    expect(RateLimiter::attempts('user@example.test|127.0.0.1'))->toBe(0);
});

it('records the login timestamp', function (): void {
    $user = User::factory()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    expect($user->last_login_at)->toBeNull();

    $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ])->assertNoContent();

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('never exposes credentials through the current user endpoint', function (): void {
    $user = User::factory()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    $body = $this->actingAs($user)->getJson('/api/v1/me')->getContent();

    expect($body)
        ->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain($user->getAuthPassword())
        ->not->toContain('is_active')
        ->not->toContain('last_login_at');
});

it('honours remember me through the native cookie rather than a token', function (): void {
    $user = User::factory()->create([
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
    ]);

    $response = $this->postJson('/login', [
        'email' => 'user@example.test',
        'password' => 'correct-horse-battery',
        'remember' => true,
    ])->assertNoContent();

    // Laravel's own remember cookie, keyed on the recaller name.
    $response->assertCookie(Auth::guard('web')->getRecallerName());

    expect($user->fresh()->remember_token)->not->toBeNull();
});

it('keeps the health endpoint public', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});
