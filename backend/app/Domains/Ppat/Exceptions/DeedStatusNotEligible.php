<?php

namespace App\Domains\Ppat\Exceptions;

use App\Domains\Ppat\Enums\PpatDeedStatus;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The act is not available from the Deed's current status (M7.2, D-121).
 *
 * **422 rather than 403**, for the reason `DocumentStatusNotEligible` gives: the
 * caller *is* authorized — the Policy already said so — and would succeed on a deed
 * in a different state. A 403 would send them to ask an administrator for a
 * permission that would not help.
 *
 * **422 rather than 409** as well: the obstacle is a field of the very record being
 * addressed, which the caller has just read. `06_API_CONVENTIONS.md` section 8
 * reserves Conflict for state the request could not have known about.
 *
 * The message names the status and the act for logs and developers. The interface
 * shows its own translated explanation, so no server string reaches a user
 * (`CLAUDE.md` section 48).
 */
class DeedStatusNotEligible extends UnprocessableEntityHttpException
{
    public function __construct(PpatDeedStatus $current, string $act)
    {
        parent::__construct(
            "A PPAT deed with status [{$current->value}] cannot be {$act}."
        );
    }
}
