<?php

namespace App\Domains\Identity\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A pending email change cannot be completed.
 *
 * Four different ways to fail, one 422 response, and deliberately one shared
 * shape of message. Distinguishing "no request pending" from "wrong token"
 * would tell somebody probing the endpoint which half of their guess was right;
 * the person who actually clicked the link in their own mailbox needs no such
 * hint, because for them it simply works.
 *
 * The address-taken case is the exception worth naming, because that one is
 * actionable: the user must pick a different address, and saying so is not a
 * disclosure they could not already make by trying to register it.
 *
 * 422 rather than 403 — the caller is authenticated and authorized; it is the
 * submitted token or the state behind it that cannot be processed
 * (docs/06_API_CONVENTIONS.md section 8).
 */
class EmailChangeUnavailable extends UnprocessableEntityHttpException
{
    public static function notPending(): self
    {
        return new self('This email change request is no longer valid.');
    }

    public static function invalidToken(): self
    {
        return new self('This email change request is no longer valid.');
    }

    public static function expired(): self
    {
        return new self('This email change request is no longer valid.');
    }

    public static function addressTaken(): self
    {
        return new self('That email address is already in use.');
    }
}
