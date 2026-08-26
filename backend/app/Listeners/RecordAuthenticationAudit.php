<?php

namespace App\Listeners;

use App\Domains\Audit\Enums\AuditEvent;
use App\Domains\Audit\Services\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Records who signed in and who signed out (M8.1, D-123).
 *
 * ## `Login`, deliberately not `Authenticated`
 *
 * The M8.1 brief proposed listening to `Authenticated`. That event fires on
 * **every authenticated request**, not once per session — a single page load
 * behind an SPA produces several. Auditing it would write thousands of rows a day
 * that say nothing, and would bury the events that matter in a table nobody may
 * delete from (`CLAUDE.md` section 31).
 *
 * `Login` fires when a session is established, which is the fact an auditor
 * actually asks about.
 *
 * ## The user is taken from the event, never from `auth()`
 *
 * On logout the guard has usually already forgotten the user by the time
 * listeners run, so `auth()->user()` is null and the row would have no actor.
 * `Logout::$user` still carries it.
 *
 * ## Nothing about the credential is recorded
 *
 * No password, no session id, no token, no remember-me cookie — `CLAUDE.md`
 * section 32. The subject of the row is the User, and the User's own attributes
 * are not copied into `new_values` either: an audit row for a login is about an
 * event, not about the state of the account.
 */
class RecordAuthenticationAudit
{
    /**
     * **The methods are `onLogin` / `onLogout`, not `handleLogin` / `handleLogout`,
     * and the names matter.**
     *
     * Laravel's event discovery registers any listener method matching `handle*`
     * automatically. With the explicit registration in `AppServiceProvider` as
     * well, `handleLogin` was bound **twice** and a single sign-in wrote two
     * identical audit rows — caught by the test asserting one login produces one
     * row. Renaming leaves exactly one registration, and keeps it the explicit
     * one, which is how every policy in this application is wired.
     */
    public function __construct(private readonly AuditLogger $audit) {}

    public function onLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->audit->log(AuditEvent::LOGIN, $user, $user);
    }

    public function onLogout(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->audit->log(AuditEvent::LOGOUT, $user, $user);
    }
}
