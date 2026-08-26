<?php

namespace App\Providers;

use App\Listeners\RecordAuthenticationAudit;
use App\Models\Company;
use App\Models\Document;
use App\Models\Individual;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\NotaryDeed;
use App\Models\PpatDeed;
use App\Models\PpatWarkah;
use App\Models\ProjectParty;
use App\Models\Property;
use App\Models\ServiceType;
use App\Models\Task;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\IndividualPolicy;
use App\Policies\MatterPartyPolicy;
use App\Policies\MatterPolicy;
use App\Policies\NotaryDeedPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\PpatDeedPolicy;
use App\Policies\PpatWarkahPolicy;
use App\Policies\ProjectPartyPolicy;
use App\Policies\PropertyPolicy;
use App\Policies\RolePolicy;
use App\Policies\ServiceTypePolicy;
use App\Policies\TaskPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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

        // Project participation (M3.4). Registered explicitly because its
        // abilities are unusual enough to be worth pointing at: they take the
        // parent **Project**, not a ProjectParty row, since participation
        // authority is judged against the Project's Data Scope (D-098). Callers
        // authorize with `[ProjectParty::class, $project]`.
        Gate::policy(ProjectParty::class, ProjectPartyPolicy::class);

        // The Service Type catalogue (M4.1). Laravel would discover this pair on
        // its own; it is listed here so one file still answers "which policy
        // guards what" for every model in the application.
        Gate::policy(ServiceType::class, ServiceTypePolicy::class);

        // Matter (M4.2). Registered explicitly because its abilities are unusual
        // enough to be worth pointing at: every one takes an explicit
        // `MatterDomain` supplied by the caller's route context, which is what
        // selects the permission namespace (D-101). `create` additionally takes
        // the parent Project. Callers authorize with `[$matter, $domain]`, or
        // `[Matter::class, $domain, $project]` for creation.
        Gate::policy(Matter::class, MatterPolicy::class);

        // Matter participation (M4.5). Two unusual things at once, which is why
        // this is spelled out: the abilities take the parent **Matter**, not a
        // MatterParty row, because participation authority is judged against the
        // Matter's Data Scope (D-105); and they additionally take an explicit
        // `MatterDomain` from the caller's route, which selects between
        // `notary.matters.parties.*` and `ppat.matters.parties.*` (D-101).
        // Callers authorize with `[MatterParty::class, $matter, $domain]`.
        Gate::policy(MatterParty::class, MatterPartyPolicy::class);

        // Document (M5.1). Laravel would discover this pair on its own; it is
        // listed here so one file still answers "which policy guards what". Two
        // things about it are worth pointing at: `create` takes an Office id
        // rather than a model, because filing is always into the actor's own
        // Office; and `view`, `download` and the rest apply
        // `documents.sensitive.*` as a **separate** capability on top of reach,
        // never as an escalation of the ordinary code (D-115).
        Gate::policy(Document::class, DocumentPolicy::class);

        // Task (M5.4). Listed here with the rest so one file answers "which policy
        // guards what". Two things about it are worth pointing at: `create` takes
        // an Office id rather than a model, because work is raised in the actor's
        // own Office; and `OWN` and `ASSIGNED` are **separate predicates** —
        // `created_by` and `assigned_to` — which union when an actor holds both
        // rather than one containing the other (D-119).
        Gate::policy(Task::class, TaskPolicy::class);

        // Notarial Deed (M6.1, D-120). Two things about it are worth pointing at.
        // `OWN` and `ASSIGNED` resolve through the **parent Matter**, because a deed
        // carries no owner column of its own — the Matter supplies the predicate and
        // never the grant, so `notary.matters.view` still reaches no deed. And there
        // is deliberately **no `delete`, `lock` or `void` ability**: the catalogue
        // defines no code for any of them, and the correction mechanisms that would
        // need them are open domain questions.
        Gate::policy(NotaryDeed::class, NotaryDeedPolicy::class);

        // PPAT Deed (M7.1, D-121). The structural twin of the Notary policy above:
        // OWN and ASSIGNED resolve through the parent Matter, and there is no
        // delete, lock or void ability because the catalogue defines no code for
        // any of them.
        Gate::policy(PpatDeed::class, PpatDeedPolicy::class);

        // Property (M7.1, D-121). Two scopes rather than four — a Property is
        // office-owned reference data, so OWN and ASSIGNED are withheld the way
        // D-080 withheld them for Party. Ownership is its own capability pair,
        // separate from viewing and updating the parcel itself.
        Gate::policy(Property::class, PropertyPolicy::class);

        // Warkah (M7.4, D-121). Its own family of six codes, four of which are
        // implemented — `finalize` and `archive` have no ability and no route,
        // because their trigger is open question eight (O-041, D-064).
        //
        // Every ability takes the parent Deed as its subject: a Warkah's reach *is*
        // its deed's reach, and the bundle may not exist yet. Registered against
        // `PpatWarkah` so the class-level form `authorize('manage', [PpatWarkah::class,
        // $deed])` resolves here.
        Gate::policy(PpatWarkah::class, PpatWarkahPolicy::class);

        $this->registerSecurityRateLimiters();
        $this->registerAuditListeners();
    }

    /**
     * Session events that belong in the audit trail (M8.1, D-123).
     *
     * Registered explicitly rather than by listener auto-discovery, for the same
     * reason every policy above is: one file answers what is wired to what.
     *
     * **`Login`, not `Authenticated`.** The latter fires on every authenticated
     * request, so auditing it would write thousands of rows a day that say
     * nothing and bury the ones that matter — in a table nobody may delete from.
     */
    private function registerAuditListeners(): void
    {
        Event::listen(Login::class, [RecordAuthenticationAudit::class, 'onLogin']);
        Event::listen(Logout::class, [RecordAuthenticationAudit::class, 'onLogout']);
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
