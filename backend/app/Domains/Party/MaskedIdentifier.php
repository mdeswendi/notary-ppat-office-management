<?php

namespace App\Domains\Party;

use SensitiveParameter;

/**
 * Server-side masking for a sensitive identifier (D-082).
 *
 * The mask is computed here and the raw value never leaves the server unless a
 * field-specific reveal permission allowed it. Masking in the browser would mean
 * the raw value had already been sent — at which point it is also in the network
 * log, the query cache, and any proxy in between. That is the difference between
 * privacy and the appearance of it.
 *
 * The pattern keeps the last four characters and replaces everything before them
 * with asterisks, matching the example in `07_SECURITY_RULES.md` section 12:
 *
 *     3174012345678901  ->  ************8901
 *
 * Four is enough for a person to recognize their own record and far too few to
 * reconstruct one. No canonical document fixes the exact pattern, so one simple
 * consistent rule is chosen and tested rather than varied per field.
 *
 * **Length is never treated as validity.** A short or odd value is masked
 * completely rather than partially revealed, because the alternative — showing
 * more of a value precisely when it looks malformed — is backwards. Nothing here
 * validates or normalizes: format rules stay deferred (D-082), and this class
 * must never be the place someone quietly adds one.
 */
final class MaskedIdentifier
{
    /**
     * How many trailing characters stay visible.
     */
    private const VISIBLE = 4;

    /**
     * Mask a raw identifier for display.
     *
     * Returns null for null, so an absent identifier stays absent rather than
     * becoming a row of asterisks that implies a value exists.
     */
    public static function mask(#[SensitiveParameter] ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $length = mb_strlen($trimmed);

        // Too short to reveal any of safely: a two-character tail of a
        // five-character value discloses most of it.
        if ($length <= self::VISIBLE) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - self::VISIBLE).mb_substr($trimmed, -self::VISIBLE);
    }
}
