<?php

namespace App\Domains\Authorization\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A role still held by someone cannot be deleted.
 *
 * 409 rather than 422: the request is well formed and the caller is authorized.
 * What blocks it is the current state of the system, which is exactly what
 * Conflict means (`docs/06_API_CONVENTIONS.md` section 8).
 *
 * The message is for logs and developers. The interface shows its own
 * translated explanation keyed off the status, so no server string reaches a
 * user (CLAUDE.md section 48).
 */
class RoleIsAssigned extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The role is currently assigned and cannot be deleted.');
    }
}
