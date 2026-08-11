<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remove two-factor authentication from an account.
 *
 * Two callers, one behaviour:
 *
 * - the account owner, who has just re-proved their password;
 * - an administrator holding `security.mfa.manage`, for the person who lost
 *   their phone and their recovery codes.
 *
 * Both clear the same four columns. There is no "administrator sees the secret"
 * variant and no way to move a secret between accounts — the only administrative
 * power here is to *remove*, after which the user enrols again from their own
 * screen and is the only one who ever sees the new secret (D-076).
 *
 * Sessions are left alone. Disabling a second factor does not call the password
 * into question, and signing everybody out would punish the person who just
 * regained access to their own account.
 *
 * `$reason` is recorded for the administrative path so the log says why an
 * account's protection was reduced, which is the question an audit asks.
 */
class DisableTwoFactor
{
    public function handle(User $user, ?User $actor = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($user): void {
            $user->two_factor_secret = null;
            $user->two_factor_recovery_codes = null;
            $user->two_factor_confirmed_at = null;
            $user->two_factor_setup_expires_at = null;
            $user->save();
        });

        Log::info('TWO_FACTOR_DISABLED', [
            'user_id' => $user->getKey(),
            // Present only when somebody else did it, which is exactly the case
            // worth being able to find later.
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
        ]);
    }
}
