<?php

namespace App\Domains\Demo\Exceptions;

use RuntimeException;

/**
 * The demo dataset's marker Office already exists.
 *
 * Refuse-not-overwrite, the same discipline `BootstrapDeploymentCommand` uses
 * for deployment identity (D-058): finding the marker means a dataset already
 * exists, and merging into it or silently regenerating it could discard
 * edits someone made to it since. Nothing is read or written beyond the one
 * marker check that produced this exception.
 */
class DemoDatasetAlreadyExists extends RuntimeException
{
    public static function markedBy(string $officeCode): self
    {
        return new self(
            "The demo dataset already exists (Office code \"{$officeCode}\" is present). "
            .'Nothing was changed. There is no demo:reset in this codebase yet — '
            .'removing the existing dataset, if that is what is wanted, is a separate, deliberate action.'
        );
    }
}
