<?php

namespace App\Domains\Identity\Actions;

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
 * Existing sessions are deliberately not revoked here. `LoginRequest` already
 * refuses a disabled account at authentication, so no new session can be
 * established; terminating the ones already open is session management, which
 * is M1.9's subject and needs its own design.
 */
class SetUserActivation
{
    public function handle(User $actor, User $target, bool $active): User
    {
        if (! $active && $actor->getKey() === $target->getKey()) {
            throw new CannotDisableSelf;
        }

        // Not mass assignable, by design — see the User model.
        $target->is_active = $active;
        $target->save();

        return $target->load('office');
    }
}
