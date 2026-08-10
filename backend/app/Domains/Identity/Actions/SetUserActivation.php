<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Authorization\AuthorizationContinuity;
use App\Domains\Identity\Exceptions\CannotDisableSelf;
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
 * Existing sessions are deliberately not revoked here. `LoginRequest` already
 * refuses a disabled account at authentication, so no new session can be
 * established; terminating the ones already open is session management, which
 * is M1.9's subject and needs its own design.
 */
class SetUserActivation
{
    public function __construct(private readonly AuthorizationContinuity $continuity) {}

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
