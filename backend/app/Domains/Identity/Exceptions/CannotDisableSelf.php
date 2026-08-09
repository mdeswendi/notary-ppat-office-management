<?php

namespace App\Domains\Identity\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An administrator may not disable their own account.
 *
 * Not an authorization failure — the actor holds `users.disable` and the target
 * is within their scope, so 403 would be a lie. What blocks it is the state of
 * the request: disabling yourself ends your own session's access, and if you
 * were the only active administrator nobody can undo it. 409 Conflict is the
 * accurate answer (docs/06_API_CONVENTIONS.md section 8).
 *
 * This is a technical safety rule, not a privileged-account rule: no role name
 * is consulted, and it applies identically at every Data Scope including `ALL`.
 * Reactivation is another authorized user's job (D-052).
 */
class CannotDisableSelf extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('You cannot disable your own account.');
    }
}
