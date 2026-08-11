<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registered explicitly because Laravel's policy discovery maps
        // App\Models\* to App\Policies\*, and the Role model belongs to
        // spatie/laravel-permission rather than to us. The package model is
        // kept as-is (D-045) instead of being subclassed just to make
        // auto-discovery work.
        Gate::policy(Role::class, RolePolicy::class);

        // Registered explicitly for symmetry with the line above. Laravel would
        // discover App\Models\User -> App\Policies\UserPolicy on its own, but
        // finding both policies in one place beats knowing which of two
        // mechanisms applies to which model.
        Gate::policy(User::class, UserPolicy::class);

        // The authorization configuration itself: the permission catalogue,
        // role grants, and role membership all authorize through this.
        Gate::policy(Permission::class, PermissionPolicy::class);

        $this->registerSecurityRateLimiters();
    }

    /**
     * Rate limits for the account-security endpoints.
     *
     * Named limiters rather than bare `throttle:6,1`, because the *sharing*
     * between buckets is a deliberate decision in one case and a bug in the
     * other. Laravel's unnamed throttle keys authenticated requests on the user
     * id alone, so every route carrying it would share one budget by accident —
     * mistyping a password three times would then block starting two-factor
     * enrolment (D-071).
     *
     * Two buckets, split on what the endpoint accepts:
     *
     * `security.password` — every endpoint taking `current_password`. They share
     * one budget **on purpose**: the risk is `current_password` being used as an
     * oracle to test guesses, and an attacker who could rotate between four
     * endpoints would otherwise get four times the attempts.
     *
     * `security.two-factor` — enrolment, confirmation, and email-token
     * verification. No password is submitted, so these do not belong in the
     * bucket above; they are limited separately, and a little more generously,
     * because a person setting up an authenticator legitimately retries.
     *
     * `security.admin` — triggering a reset on somebody else's account. Keyed on
     * the administrator, because the abuse it guards against is mailing a
     * colleague repeatedly rather than guessing anything.
     */
    private function registerSecurityRateLimiters(): void
    {
        RateLimiter::for('security.password', fn (Request $request): Limit => Limit::perMinute(6)
            ->by($this->throttleIdentity($request)));

        RateLimiter::for('security.two-factor', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($this->throttleIdentity($request)));

        RateLimiter::for('security.admin', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($this->throttleIdentity($request)));
    }

    /**
     * The account a limit applies to, falling back to the source address.
     *
     * Never the submitted password, code, or token: including one would give
     * every guess its own bucket and make the limit meaningless.
     */
    private function throttleIdentity(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
    }
}
