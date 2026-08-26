<?php

namespace App\Domains\Billing\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A billing record is not in a state that permits the act (M8.2).
 *
 * The billing twin of `DeedStatusNotEligible` and `TaskStatusNotEligible`, and
 * rendered the same way: **422**, because the request was well formed and the
 * record's state refused it. A 403 would say the actor may not do this, which is
 * a different and misleading answer — they may, just not to this row, and not
 * yet or not any more.
 *
 * The message names the state the record is actually in, so the interface can
 * explain the refusal without guessing.
 */
class BillingStatusNotEligible extends UnprocessableEntityHttpException
{
    public static function for(string $subject, string $status, string $act): self
    {
        return new self(
            "This {$subject} is {$status} and cannot be {$act}."
        );
    }
}
