<?php

namespace App\Domains\Identity;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication.
 *
 * RFC 6238 through `pragmarx/google2fa`, and QR rendering through
 * `bacon/bacon-qr-code` — the pair Laravel Fortify uses. **No cryptography is
 * written here**: TOTP is easy to implement subtly wrong, and a subtly wrong
 * one-time-password scheme fails silently rather than loudly (D-076).
 *
 * A one-window drift tolerance is allowed, so a code entered as it rolls over
 * still works. Wider tolerance would extend how long an observed code stays
 * usable.
 *
 * Recovery codes are hashed with the application hasher and consumed
 * one at a time. They are returned in raw form exactly once, at generation, and
 * are unrecoverable afterwards — including to an administrator, which is the
 * point rather than a limitation.
 */
class TwoFactor
{
    /**
     * How many codes a confirmation or regeneration produces.
     */
    public const RECOVERY_CODE_COUNT = 8;

    /**
     * How long an unconfirmed enrolment stays usable.
     */
    public const SETUP_TTL_MINUTES = 30;

    /**
     * Accepted drift, in 30-second windows, either side of now.
     */
    private const DRIFT_WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * The `otpauth://` URI an authenticator app scans.
     *
     * The account label is the user's email so somebody with several accounts
     * can tell the entries apart; the issuer is the application name.
     */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) config('app.name'),
            $user->email,
            $secret,
        );
    }

    /**
     * An inline SVG QR code for the provisioning URI.
     *
     * Rendered server-side so the browser needs no QR library, and returned as
     * markup rather than a URL so the secret never becomes a fetchable
     * resource.
     */
    public function qrCodeSvg(string $provisioningUri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 0), new SvgImageBackEnd));

        return $writer->writeString($provisioningUri);
    }

    public function verify(string $secret, string $code): bool
    {
        // Reject anything that is not a plain 6-digit code before it reaches
        // the library, so odd input cannot produce odd behaviour.
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code, self::DRIFT_WINDOW);
    }

    /**
     * Fresh recovery codes, raw. The caller stores the hashes and shows these
     * once.
     *
     * @return array<int, string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /**
     * @param  array<int, string>  $rawCodes
     * @return array<int, string>
     */
    public function hashRecoveryCodes(array $rawCodes): array
    {
        return array_map(static fn (string $code): string => Hash::make($code), $rawCodes);
    }

    /**
     * Consume one recovery code, if it matches an unused one.
     *
     * Returns the remaining hashes when the code was valid, or null when it was
     * not. The caller persists the remainder, which is what makes a code
     * single-use: the matching hash is gone from the stored set.
     *
     * @param  array<int, string>  $storedHashes
     * @return array<int, string>|null
     */
    public function consumeRecoveryCode(array $storedHashes, string $code): ?array
    {
        foreach ($storedHashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                $remaining = $storedHashes;
                unset($remaining[$index]);

                return array_values($remaining);
            }
        }

        return null;
    }

    /**
     * Does this code satisfy the second factor, either as a TOTP or as an
     * unused recovery code?
     *
     * Returns the recovery-code remainder to persist when a recovery code was
     * used, so the caller cannot forget to consume it.
     *
     * @return array{valid: bool, remainingRecoveryCodes: array<int, string>|null}
     */
    public function verifySecondFactor(User $user, string $code): array
    {
        if ($user->two_factor_secret !== null && $this->verify($user->two_factor_secret, $code)) {
            return ['valid' => true, 'remainingRecoveryCodes' => null];
        }

        $remaining = $this->consumeRecoveryCode($user->two_factor_recovery_codes ?? [], $code);

        return [
            'valid' => $remaining !== null,
            'remainingRecoveryCodes' => $remaining,
        ];
    }
}
