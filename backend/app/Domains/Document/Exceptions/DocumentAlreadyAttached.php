<?php

namespace App\Domains\Document\Exceptions;

use App\Domains\Document\Enums\DocumentRelationType;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The Document is already attached to that record (M5.3, D-118).
 *
 * **A surface rule, not a schema rule, and the distinction is deliberate.** The
 * junction tables carry no `UNIQUE (owner_id, document_id)`: M5.1 declined to
 * invent a cardinality rule no canonical document states, because *"a unique index
 * is a business rule wearing an index's clothing"* (D-116, following D-105 and
 * D-110).
 *
 * D-110 also said what to do if an office decides duplicates are wrong — *"that is
 * a rule to state and validate"* — and this is that rule, stated and validated.
 * The schema stays open, so an office that later needs a second attachment is not
 * blocked by a migration; the attach surface refuses what is almost always a
 * double click.
 *
 * 422 rather than 409: the obstacle is a row the caller can already see in the
 * relation list they just read, and `06_API_CONVENTIONS.md` section 8 reserves
 * Conflict for state the request could not have known about.
 */
class DocumentAlreadyAttached extends UnprocessableEntityHttpException
{
    public function __construct(DocumentRelationType $type)
    {
        parent::__construct(
            "This document is already attached to that {$type->value}."
        );
    }
}
