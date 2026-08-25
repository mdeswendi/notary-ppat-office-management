<?php

namespace App\Domains\Ppat\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The act is not available from the Property's current state (M7.3, D-121).
 *
 * **422 rather than 403**, the shape {@see DeedStatusNotEligible} explains: the caller
 * *is* authorized — the Policy already said so — and the same request would succeed
 * once the obstacle clears. A 403 would send them to ask an administrator for a
 * permission that would not help.
 *
 * The message names the obstacle for logs and developers. The interface shows its own
 * translated explanation, so no server string reaches a user (`CLAUDE.md` section 48).
 */
class PropertyNotEligible extends UnprocessableEntityHttpException
{
    public static function becauseMattersAreStillRunning(int $count): self
    {
        return new self(
            "This Property is named by {$count} Matter(s) that have not finished, "
            .'so it cannot be archived yet.'
        );
    }

    public static function becauseOwnershipIsAlreadyClosed(): self
    {
        return new self(
            'This link in the chain of title is already closed. '
            .'A closed link is history and is never reopened or rewritten.'
        );
    }
}
