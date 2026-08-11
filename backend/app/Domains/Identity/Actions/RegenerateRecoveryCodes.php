<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Exceptions\TwoFactorUnavailable;
use App\Domains\Identity\TwoFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issue a fresh set of recovery codes, invalidating every previous one.
 *
 * Replacement is total rather than topping up the remainder. Somebody
 * regenerating has usually decided the old list is compromised — printed and
 * lost, or photographed — and leaving even one old code alive would keep the
 * exact hole they are trying to close.
 *
 * Requires two-factor to be confirmed. Codes that recover an account with no
 * second factor recover nothing, and issuing them would suggest a protection
 * that is not there.
 *
 * Returned raw exactly once, like enrolment. Nothing can read them back
 * afterwards (D-076).
 */
class RegenerateRecoveryCodes
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    /**
     * @return array<int, string>
     *
     * @throws TwoFactorUnavailable
     */
    public function handle(User $user): array
    {
        if (! $user->hasConfirmedTwoFactor()) {
            throw TwoFactorUnavailable::notEnabled();
        }

        $rawCodes = $this->twoFactor->generateRecoveryCodes();

        DB::transaction(function () use ($user, $rawCodes): void {
            $user->two_factor_recovery_codes = $this->twoFactor->hashRecoveryCodes($rawCodes);
            $user->save();
        });

        Log::info('TWO_FACTOR_RECOVERY_CODES_REGENERATED', ['user_id' => $user->getKey()]);

        return $rawCodes;
    }
}
