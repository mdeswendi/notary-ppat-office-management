<?php

namespace App\Domains\Identity\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A two-factor operation cannot proceed in the account's current state.
 *
 * 422 rather than 403 throughout: the caller is authenticated and authorized to
 * manage their own second factor. What fails is the state — nothing pending,
 * already confirmed, an expired window, or a code that does not verify
 * (docs/06_API_CONVENTIONS.md section 8).
 *
 * {@see invalidCode()} deliberately says only that the code was not accepted. It
 * does not distinguish a wrong TOTP from an already-used recovery code, because
 * that distinction tells somebody guessing which pool they are guessing in.
 */
class TwoFactorUnavailable extends UnprocessableEntityHttpException
{
    public static function noPendingSetup(): self
    {
        return new self('Start two-factor setup again before confirming it.');
    }

    public static function alreadyConfirmed(): self
    {
        return new self('Two-factor authentication is already enabled on this account.');
    }

    public static function notEnabled(): self
    {
        return new self('Two-factor authentication is not enabled on this account.');
    }

    public static function invalidCode(): self
    {
        return new self('That code was not accepted.');
    }
}
