<?php

namespace App\Domains\Task\Exceptions;

use App\Domains\Task\Enums\TaskStatus;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The act is not available from the Task's current status (M5.4, D-119).
 *
 * **422 rather than 403**: the caller *is* authorized — the Policy already said
 * so — and would succeed on a task in a different state. A 403 would send them to
 * an administrator for a permission that would not help.
 *
 * **422 rather than 409** as well: the obstacle is a field of the very record
 * being addressed, which the caller has just read.
 * `06_API_CONVENTIONS.md` section 8 reserves Conflict for state the request could
 * not have known about.
 */
class TaskStatusNotEligible extends UnprocessableEntityHttpException
{
    public function __construct(TaskStatus $current, string $act)
    {
        parent::__construct(
            "A task with status [{$current->value}] cannot be {$act}."
        );
    }
}
