<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Individual;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\IndividualPolicy;
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

        // The Party domain (M2.1). Registered here with the rest rather than
        // relying on auto-discovery, so one file answers "which policy guards
        // what" for every model in the application.
        Gate::policy(Individual::class, IndividualPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);

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

        $this->registerIdentityRevealRateLimiters();
    }

    /**
     * Rate limits for sensitive identity reveal (M2.2, extended to Company at M2.3).
     *
     * **Deliberately separate from the `security.*` buckets.** M1.9 shipped a
     * shared unnamed throttle and produced exactly the defect this avoids:
     * spending one route's budget silently disabled an unrelated one. Somebody
     * revealing identifiers while working through a directory must not find
     * their own password change refused, and vice versa.
     *
     * **Every Party identity reveal shares one bucket, on purpose.** Individual
     * NIK, Individual NPWP, and Company `tax_id` (M2.3) are the same kind of
     * disclosure, so a caller enumerating identity data should not get two or
     * three times the budget by alternating fields — or by alternating between
     * subtypes. This is the same reasoning that made every `current_password`
     * route share one bucket: the sharing is chosen where it closes a hole and
     * avoided where it opens one.
     *
     * Keyed on the actor. The limit is a brake on bulk disclosure, never a
     * substitute for authorization: an unauthorized caller is refused by the
     * Policy whether or not any budget remains.
     */
    private function registerIdentityRevealRateLimiters(): void
    {
        RateLimiter::for('party.identity.reveal', fn (Request $request): Limit => Limit::perMinute(20)
            ->by($this->throttleIdentity($request)));

        /*
         * Advisory duplicate checks (M2.5), in their **own** bucket.
         *
         * A duplicate check asks whether an identifier already exists; a reveal
         * discloses one that does. Different operations, so different budgets —
         * exhausting the check must not disable reveal, and a person working
         * through a directory must not find their password change refused
         * either. That is the M1.9 defect stated for a third surface.
         *
         * More generous than reveal, because a form may check several times
         * while somebody types, and the check discloses far less: a candidate
         * list carries no identifier at all.
         */
        RateLimiter::for('party.duplicate.check', fn (Request $request): Limit => Limit::perMinute(30)
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
