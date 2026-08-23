<?php

namespace App\Domains\Document\Exceptions;

use App\Domains\Document\Enums\DocumentStatus;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `is_sensitive` may not be changed once the Document is settled (M5.2, D-117).
 *
 * Verification is the moment somebody looked at the document and accepted it as
 * what it claims to be, its classification included. Flipping the flag afterwards
 * would silently redefine which capability is needed to download a file that has
 * already been accepted — turning a sensitive record ordinary, or the reverse,
 * without anything recording that the rules changed.
 *
 * 422 for the reason {@see DocumentStatusNotEligible} gives: the caller is
 * authorized and the request is well formed; the field is simply no longer open.
 */
class SensitivityIsSettled extends UnprocessableEntityHttpException
{
    public function __construct(DocumentStatus $current)
    {
        parent::__construct(
            "Sensitivity cannot be changed on a document with status [{$current->value}]."
        );
    }
}
