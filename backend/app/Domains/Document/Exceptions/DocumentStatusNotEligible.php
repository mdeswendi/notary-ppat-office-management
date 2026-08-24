<?php

namespace App\Domains\Document\Exceptions;

use App\Domains\Document\Enums\DocumentStatus;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The act is not available from the Document's current status (M5.2, D-117).
 *
 * **422 rather than 403**, and the difference is not cosmetic: the caller *is*
 * authorized — the Policy already said so — and would succeed on a document in a
 * different state. A 403 would tell them to ask an administrator for a permission
 * that would not help.
 *
 * **422 rather than 409** as well, which is the closer call. `RoleIsAssigned`
 * answers 409 because the obstacle is another record's existence, which the caller
 * cannot see. Here the obstacle is a field of the very record being addressed, and
 * the caller has just read it — `06_API_CONVENTIONS.md` section 8 reserves Conflict
 * for state the request could not have known about.
 *
 * The message names the status and the act for logs and developers. The interface
 * shows its own translated explanation keyed off the status code, so no server
 * string reaches a user (`CLAUDE.md` section 48).
 */
class DocumentStatusNotEligible extends UnprocessableEntityHttpException
{
    public function __construct(DocumentStatus $current, string $act)
    {
        parent::__construct(
            "A document with status [{$current->value}] cannot be {$act}."
        );
    }
}
