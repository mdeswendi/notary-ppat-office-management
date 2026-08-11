<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Authorization\AuthorizationContinuity;
use App\Domains\Identity\Exceptions\CannotDisableSelf;
use App\Domains\Identity\SessionRegistry;
use App\Models\User;

/**
 * Turns a user account on or off.
 *
 * One action for both directions, so enabling and disabling cannot drift apart.
 * `is_active` is reachable only from here and never from the update endpoint,
 * which keeps activation a deliberate act guarded by its own capability
 * (`users.disable`) rather than a field somebody edits in passing (D-052).
 *
 * Idempotent: disabling an already disabled account, or enabling an already
 * enabled one, simply leaves it as it is.
 *
 * Disabling yourself is refused. See {@see CannotDisableSelf}.
 *
 * Disabling the **last** person able to administer authorization is refused too,
 * with 409 (D-056). M1.5 only had to stop you locking yourself out; M1.6 made
 * the permission configuration editable, so an administrator disabling the last
 * remaining administrator is the same lockout reached by another route — the
 * deployment would keep working and become permanently unconfigurable.
 *
 * **Disabling now ends the account's open sessions.** M1.5 left this out, and it
 * left a real hole: `LoginRequest` refuses a disabled account at authentication,
 * so no *new* session could start, but every session already open kept working
 * until it expired. Disabling somebody during an incident has to take effect
 * immediately, not whenever their cookie happens to lapse (D-074).
 */
class SetUserActivation
{
    public function __construct(
        private readonly AuthorizationContinuity $continuity,
        private readonly SessionRegistry $sessions,
    ) {}

    public function handle(User $actor, User $target, bool $active): User
    {
        if (! $active && $actor->getKey() === $target->getKey()) {
            throw new CannotDisableSelf;
        }

        // Enabling can only ever add an administrator, so only the disabling
        // direction needs the invariant — and it runs in the same transaction
        // as the change, so it reads the state the change produced.
        if (! $active) {
            $this->continuity->protecting(fn () => $this->apply($target, false));

            // After the invariant has held, so a refused disable does not sign
            // anybody out. Nothing spared: this is the whole point.
            $this->sessions->revokeAll($target);
        } else {
            $this->apply($target, true);
        }

        return $target->load('office');
    }

    private function apply(User $target, bool $active): void
    {
        // Not mass assignable, by design — see the User model.
        $target->is_active = $active;
        $target->save();
    }
}
