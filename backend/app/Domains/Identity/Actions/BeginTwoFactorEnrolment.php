<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\TwoFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Start two-factor enrolment: generate a secret and show it once.
 *
 * The secret is stored immediately but **not confirmed**, so nothing changes
 * about how the account logs in. `two_factor_confirmed_at` stays null until a
 * code proves the authenticator actually works, which is what keeps a failed
 * scan from becoming a lockout (D-076).
 *
 * Starting again replaces any unconfirmed secret. Somebody who abandoned setup
 * and came back should not be silently handed the old QR code their
 * authenticator may or may not still have.
 *
 * An enrolment already confirmed is left completely alone — re-enrolling would
 * quietly invalidate a working authenticator, so the controller refuses that
 * before reaching here.
 *
 * The unconfirmed secret expires after {@see TwoFactor::SETUP_TTL_MINUTES}, so
 * an abandoned attempt does not leave a usable secret sitting in the row
 * indefinitely.
 */
class BeginTwoFactorEnrolment
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    /**
     * @return array{secret: string, provisioning_uri: string, qr_code_svg: string}
     */
    public function handle(User $user): array
    {
        $secret = $this->twoFactor->generateSecret();

        DB::transaction(function () use ($user, $secret): void {
            $user->two_factor_secret = $secret;
            $user->two_factor_confirmed_at = null;
            // Any recovery codes from a previous enrolment go now. Codes that
            // unlock a secret nobody holds are worse than none.
            $user->two_factor_recovery_codes = null;
            $user->two_factor_setup_expires_at = now()->addMinutes(TwoFactor::SETUP_TTL_MINUTES);
            $user->save();
        });

        Log::info('TWO_FACTOR_ENROLMENT_STARTED', ['user_id' => $user->getKey()]);

        $uri = $this->twoFactor->provisioningUri($user, $secret);

        // Returned to the enrolling user's own screen and nowhere else. The
        // secret is not readable from any other endpoint once this response is
        // gone.
        return [
            'secret' => $secret,
            'provisioning_uri' => $uri,
            'qr_code_svg' => $this->twoFactor->qrCodeSvg($uri),
        ];
    }
}
