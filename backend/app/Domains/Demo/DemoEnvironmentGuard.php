<?php

namespace App\Domains\Demo;

use App\Domains\Demo\Exceptions\UnsafeDemoEnvironment;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Fails closed unless the active connection is unmistakably the local demo
 * database.
 *
 * **This is not an authorization check.** It answers one question only —
 * "is it safe for local demo tooling to write here" — and has nothing to say
 * about who the caller is or what permission they hold. It exists because
 * demo data must be generated through the same Actions the product uses, so
 * it exercises the same validation and allocators everything else does, and
 * those Actions write for real: there is no dry-run mode to fall back on if
 * the wrong database happens to be connected.
 *
 * **Exactly one database name is accepted.** Not a prefix, not a substring,
 * not anything merely containing the word "demo" — `notary_ppat_demo_backup`
 * and `my_demo` are both rejected exactly like `notary_ppat_office` is. A
 * name close enough to look right is precisely the mistake this guard exists
 * to catch, which is why the comparison is `===`, never `str_contains()`.
 *
 * **The database name comes from the connection actually in use, never from
 * an environment variable.** `DB_DATABASE` describes intent; `getDatabaseName()`
 * on the live connection describes what is actually happening, and the two
 * can disagree — O-034 records a launcher that silently drops the override
 * for a child process it spawns. Reading the wrong one would let this guard
 * pass while pointed at the real database.
 *
 * **Calling this guard is the caller's responsibility, every time, before the
 * first write.** It authorizes nothing on its own and holds no hook into
 * whatever writes come after it — an Action or command that never calls
 * {@see self::assertSafe()} is not protected by this class existing
 * somewhere else in the codebase.
 */
class DemoEnvironmentGuard
{
    private const string ALLOWED_ENVIRONMENT = 'local';

    private const string ALLOWED_DATABASE = 'notary_ppat_demo';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $environment,
    ) {}

    /**
     * @throws UnsafeDemoEnvironment when the active environment or database is
     *                               not unmistakably the local demo target
     */
    public function assertSafe(): void
    {
        if ($this->environment !== self::ALLOWED_ENVIRONMENT) {
            throw UnsafeDemoEnvironment::wrongEnvironment($this->environment, self::ALLOWED_ENVIRONMENT);
        }

        try {
            $database = $this->connection->getDatabaseName();
        } catch (Throwable $e) {
            throw UnsafeDemoEnvironment::unreadableDatabase($e);
        }

        if (! is_string($database) || $database === '') {
            throw UnsafeDemoEnvironment::unreadableDatabase();
        }

        if ($database !== self::ALLOWED_DATABASE) {
            throw UnsafeDemoEnvironment::wrongDatabase($database, self::ALLOWED_DATABASE);
        }
    }
}
