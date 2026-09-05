<?php

namespace App\Domains\Demo\Exceptions;

use RuntimeException;

/**
 * The operator-supplied primary demo user password could not be accepted.
 *
 * Never carries the password, its confirmation, or a value that echoes
 * either back — CLAUDE.md section 32 and D-051 forbid printing or logging a
 * password, and a validation failure describes *which rule* rejected the
 * input, never the input itself.
 */
class DemoPrimaryActorPasswordInvalid extends RuntimeException
{
    public static function notInteractive(): self
    {
        return new self(
            'Refusing to proceed: demo:seed must ask for the primary demo user\'s password at a hidden '
            .'interactive prompt, and this run is not interactive (--no-interaction, or no attached '
            .'terminal). There is no default password and no non-interactive fallback — re-run '
            .'interactively.'
        );
    }

    public static function unavailable(): self
    {
        return new self(
            'Refusing to proceed: the password prompt was cancelled or could not read an answer. '
            .'Nothing was written.'
        );
    }

    /**
     * @param  array<int, string>  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(
            "Refusing to proceed: the primary demo user's password was not accepted:\n"
            .implode("\n", array_map(fn (string $message): string => "  - {$message}", $errors))
        );
    }
}
