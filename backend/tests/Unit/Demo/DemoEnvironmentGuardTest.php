<?php

use App\Domains\Demo\DemoEnvironmentGuard;
use App\Domains\Demo\Exceptions\UnsafeDemoEnvironment;
use Illuminate\Database\ConnectionInterface;

/**
 * Pure unit boundary — no `Tests\TestCase`, no Laravel bootstrap, no real
 * database connection anywhere in this file (`Pest.php` only extends
 * `TestCase` for the `Feature` directory, so files under `Unit/` run as bare
 * Pest tests). {@see fakeConnection()} below never opens a socket, and every
 * method on it besides `getDatabaseName()` throws immediately if called at
 * all, so this suite structurally cannot reach the persistent PostgreSQL
 * development database.
 */

/**
 * A connection double that answers exactly one question the guard asks —
 * which database it is connected to — and throws on every other call, so a
 * guard implementation that starts querying, writing, or opening a
 * transaction through the connection fails the test loudly rather than
 * silently reaching something real.
 */
function fakeConnection(?string $databaseName, ?Throwable $failure = null): ConnectionInterface
{
    return new class($databaseName, $failure) implements ConnectionInterface
    {
        public function __construct(
            private readonly ?string $databaseName,
            private readonly ?Throwable $failure,
        ) {}

        public function getDatabaseName()
        {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return $this->databaseName;
        }

        private function unreachable(string $method): never
        {
            throw new LogicException("DemoEnvironmentGuard must never call Connection::{$method}().");
        }

        public function table($table, $as = null)
        {
            $this->unreachable(__FUNCTION__);
        }

        public function raw($value)
        {
            $this->unreachable(__FUNCTION__);
        }

        public function selectOne($query, $bindings = [], $useReadPdo = true)
        {
            $this->unreachable(__FUNCTION__);
        }

        public function scalar($query, $bindings = [], $useReadPdo = true)
        {
            $this->unreachable(__FUNCTION__);
        }

        public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
        {
            $this->unreachable(__FUNCTION__);
        }

        public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
        {
            $this->unreachable(__FUNCTION__);
        }

        public function insert($query, $bindings = [])
        {
            $this->unreachable(__FUNCTION__);
        }

        public function update($query, $bindings = [])
        {
            $this->unreachable(__FUNCTION__);
        }

        public function delete($query, $bindings = [])
        {
            $this->unreachable(__FUNCTION__);
        }

        public function statement($query, $bindings = [])
        {
            $this->unreachable(__FUNCTION__);
        }

        public function affectingStatement($query, $bindings = [])
        {
            $this->unreachable(__FUNCTION__);
        }

        public function unprepared($query)
        {
            $this->unreachable(__FUNCTION__);
        }

        public function prepareBindings(array $bindings)
        {
            $this->unreachable(__FUNCTION__);
        }

        public function transaction(Closure $callback, $attempts = 1)
        {
            $this->unreachable(__FUNCTION__);
        }

        public function beginTransaction()
        {
            $this->unreachable(__FUNCTION__);
        }

        public function commit()
        {
            $this->unreachable(__FUNCTION__);
        }

        public function rollBack()
        {
            $this->unreachable(__FUNCTION__);
        }

        public function transactionLevel()
        {
            $this->unreachable(__FUNCTION__);
        }

        public function pretend(Closure $callback)
        {
            $this->unreachable(__FUNCTION__);
        }
    };
}

it('accepts local plus exactly notary_ppat_demo, and never calls a mutating method on the connection', function () {
    $guard = new DemoEnvironmentGuard(fakeConnection('notary_ppat_demo'), 'local');

    // No exception. If assertSafe() reached for select/insert/update/statement/
    // transaction on the fake connection instead of stopping at
    // getDatabaseName(), the fake would throw LogicException here and fail
    // the test — this single expectation is the proof for both claims at once.
    expect(fn () => $guard->assertSafe())->not->toThrow(Throwable::class);
});

it('rejects the real development database', function () {
    $guard = new DemoEnvironmentGuard(fakeConnection('notary_ppat_office'), 'local');

    expect(fn () => $guard->assertSafe())->toThrow(UnsafeDemoEnvironment::class);
});

it('rejects a name that merely starts with the demo database name', function () {
    $guard = new DemoEnvironmentGuard(fakeConnection('notary_ppat_demo_backup'), 'local');

    expect(fn () => $guard->assertSafe())->toThrow(UnsafeDemoEnvironment::class);
});

it('rejects a name that merely contains the word demo', function () {
    $guard = new DemoEnvironmentGuard(fakeConnection('my_demo'), 'local');

    expect(fn () => $guard->assertSafe())->toThrow(UnsafeDemoEnvironment::class);
});

it('rejects every environment other than local', function (string $environment) {
    // The environment is checked first, so a wrong environment is refused
    // before the connection is ever touched — fakeConnection() here would
    // throw LogicException on any call, proving none was made.
    $guard = new DemoEnvironmentGuard(fakeConnection(null, new LogicException('must not be reached')), $environment);

    expect(fn () => $guard->assertSafe())->toThrow(UnsafeDemoEnvironment::class);
})->with(['production', 'staging', 'testing', '']);

it('rejects an empty database name', function () {
    $guard = new DemoEnvironmentGuard(fakeConnection(''), 'local');

    expect(fn () => $guard->assertSafe())->toThrow(UnsafeDemoEnvironment::class);
});

it('rejects an unavailable database name', function () {
    $guard = new DemoEnvironmentGuard(fakeConnection(null), 'local');

    expect(fn () => $guard->assertSafe())->toThrow(UnsafeDemoEnvironment::class);
});

it('fails closed when reading the database name throws', function () {
    $guard = new DemoEnvironmentGuard(
        fakeConnection(null, new RuntimeException('SQLSTATE[08006] connection refused')),
        'local',
    );

    expect(fn () => $guard->assertSafe())->toThrow(UnsafeDemoEnvironment::class);
});

it('never leaks the underlying connection exception message into its own message', function () {
    $guard = new DemoEnvironmentGuard(
        fakeConnection(null, new RuntimeException('host=secret-db.internal user=admin password=hunter2')),
        'local',
    );

    try {
        $guard->assertSafe();
        expect(false)->toBeTrue('assertSafe() should have thrown.');
    } catch (UnsafeDemoEnvironment $e) {
        expect($e->getMessage())->not->toContain('secret-db.internal')
            ->and($e->getMessage())->not->toContain('hunter2');
    }
});
