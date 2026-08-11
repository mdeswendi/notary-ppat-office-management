<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Exceptions\TwoFactorUnavailable;
use App\Domains\Identity\TwoFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turn two-factor on, once a code from the authenticator verifies.
 *
 * This is the moment enrolment becomes real, and the only moment recovery codes
 * are ever readable. They are generated here, hashed for storage, and returned
 * raw exactly once — an administrator cannot retrieve them later, and neither
 * can the user (D-076). That is deliberate: a recovery code readable after the
 * fact is a second password sitting in the database.
 *
 * A wrong code changes nothing at all. The pending secret survives so the user
 * can try again — a clock a few seconds out should cost a retry, not the whole
 * enrolment.
 */
class ConfirmTwoFactorEnrolment
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    /**
     * @return array<int, string>
     *
     * @throws TwoFactorUnavailable
     */
    public function handle(User $user, string $code): array
    {
        if ($user->hasConfirmedTwoFactor()) {
            throw TwoFactorUnavailable::alreadyConfirmed();
        }

        if (! $user->hasPendingTwoFactorSetup()) {
            // Either enrolment was never started, or the window closed. Both
            // mean "start again", and saying which would be splitting hairs.
            throw TwoFactorUnavailable::noPendingSetup();
        }

        if (! $this->twoFactor->verify($user->two_factor_secret, $code)) {
            throw TwoFactorUnavailable::invalidCode();
        }

        $rawCodes = $this->twoFactor->generateRecoveryCodes();

        DB::transaction(function () use ($user, $rawCodes): void {
            $user->two_factor_confirmed_at = now();
            $user->two_factor_setup_expires_at = null;
            $user->two_factor_recovery_codes = $this->twoFactor->hashRecoveryCodes($rawCodes);
            $user->save();
        });

        // The event, never the secret and never a code.
        Log::info('TWO_FACTOR_ENABLED', ['user_id' => $user->getKey()]);

        return $rawCodes;
    }
}
