<?php

namespace App\Domains\Demo\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The active connection is not unmistakably the local demo database.
 *
 * The message may name the environment or database name that was rejected, or
 * the one that was expected — neither is a credential. It never includes a
 * host, username, password, DSN, or the message of an underlying connection
 * exception, which can itself carry connection details a driver chose to
 * report.
 */
class UnsafeDemoEnvironment extends RuntimeException
{
    public static function wrongEnvironment(string $actual, string $expected): self
    {
        return new self(
            "Refusing to proceed: the active environment is \"{$actual}\", not \"{$expected}\". "
            .'Demo tooling only runs in that environment.'
        );
    }

    public static function wrongDatabase(string $actual, string $expected): self
    {
        return new self(
            "Refusing to proceed: the active connection's database is \"{$actual}\", "
            ."not exactly \"{$expected}\". A name that merely contains that word is not accepted."
        );
    }

    public static function unreadableDatabase(?Throwable $previous = null): self
    {
        return new self(
            'Refusing to proceed: the active connection could not prove which database it is using. '
            .'Failing closed rather than assuming it is safe.',
            previous: $previous,
        );
    }
}
