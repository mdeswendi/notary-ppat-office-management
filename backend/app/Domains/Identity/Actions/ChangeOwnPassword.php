<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\SessionRegistry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Change your own password.
 *
 * The current password is verified by the Form Request before this runs, so
 * reaching here means the person at the keyboard proved they are the account
 * owner and not somebody who found an unlocked screen.
 *
 * Afterwards **every other session is revoked** (D-072). Changing a password is
 * usually a response to suspecting somebody else has it, and leaving their
 * session alive would make the change theatre. The session doing the changing
 * survives — logging somebody out for securing their own account teaches them
 * not to.
 *
 * The current session id is also regenerated, so the cookie that existed before
 * the change cannot be replayed afterwards.
 *
 * Roles, permissions, Data Scope metadata, overrides, Office, and locale are all
 * untouched; tests assert each of them.
 */
class ChangeOwnPassword
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    public function handle(Request $request, User $user, string $newPassword): void
    {
        DB::transaction(function () use ($request, $user, $newPassword): void {
            // Hashed by the model's `hashed` cast — no plaintext is written.
            $user->password = $newPassword;
            $user->save();

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            $this->sessions->revokeAll(
                $user,
                $request->hasSession() ? $request->session()->getId() : null,
            );
        });

        // Event name and actor only. Never the password, and never the session
        // identifier (D-020 of this milestone's design, recorded as D-072).
        Log::info('PASSWORD_CHANGED', ['user_id' => $user->getKey()]);
    }
}
