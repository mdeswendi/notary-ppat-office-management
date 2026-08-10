<?php

namespace App\Domains\Authorization\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The change would leave nobody able to administer authorization.
 *
 * 409, not 403: the caller is authorized and the request is well formed. What
 * blocks it is the state it would produce — a deployment whose permission
 * system nobody can reach, with no in-product way back (D-056).
 *
 * The mutation is rolled back, so the configuration is exactly as it was.
 */
class NoAuthorizationAdministratorWouldRemain extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct(
            'This change would leave no active user able to administer authorization.'
        );
    }
}
