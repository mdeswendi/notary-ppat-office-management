<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * An administrator asks the system to send a reset link to a user.
 *
 * **The administrator never learns anything secret** (D-071). They do not choose
 * the password, do not see a temporary one, and never receive the token — not in
 * the response, not in a log line, not in a notification payload. All they can
 * do is cause an email to go to the address already on the account.
 *
 * That constraint is the whole design. An administrator who can read or set a
 * colleague's password can act as them, and in a legal office that means signing
 * things as them. The reset link keeps the account owner as the only person who
 * ends up knowing the password.
 *
 * Triggering a reset does **not** change the current password. Until the link is
 * used, the existing password keeps working — otherwise an administrator could
 * lock somebody out mid-day by accident.
 *
 * Delivery uses Laravel's password broker, so the token is hashed at rest and
 * expires on the configured schedule.
 */
class SendPasswordResetLink
{
    public function handle(User $user): string
    {
        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        // The target and the outcome. Never the token, and never the address
        // beyond the id needed to trace it.
        Log::info('PASSWORD_RESET_REQUESTED', [
            'user_id' => $user->getKey(),
            'status' => $status,
        ]);

        return $status;
    }
}
