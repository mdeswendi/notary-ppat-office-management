<?php

namespace App\Domains\Document\Exceptions;

use App\Domains\Document\Enums\DocumentRelationType;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * There is no attachment to remove (M5.3, D-118).
 *
 * 422 rather than 404, and the choice is about *what* is missing: the Document
 * exists and the caller may reach it, so answering 404 would say the document is
 * not there. What is absent is the relationship between two records the caller
 * named, which is a fact about the request rather than about the address.
 *
 * Detaching is naturally idempotent-looking — two clicks on the same button — so
 * this is a message the interface should translate calmly rather than present as
 * a fault.
 */
class DocumentNotAttached extends UnprocessableEntityHttpException
{
    public function __construct(DocumentRelationType $type)
    {
        parent::__construct(
            "This document is not attached to that {$type->value}."
        );
    }
}
