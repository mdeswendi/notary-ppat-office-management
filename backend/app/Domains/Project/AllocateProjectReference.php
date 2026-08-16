<?php

namespace App\Domains\Project;

use App\Models\Office;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;

/**
 * Hands out the next Project internal reference for an Office and year (M3.2,
 * D-094).
 *
 * **One statement, atomic, no read-then-write.**
 *
 * ```sql
 * INSERT INTO project_reference_counters (office_id, reference_year, last_value, ...)
 * VALUES (?, ?, 1, ...)
 * ON CONFLICT (office_id, reference_year)
 * DO UPDATE SET last_value = project_reference_counters.last_value + 1, updated_at = ?
 * RETURNING last_value
 * ```
 *
 * The increment happens **inside the database**, in one statement, against a row
 * the engine locks for the duration of the upsert. Two concurrent callers cannot
 * both compute the same next value, because neither computes it at all — they ask
 * the database to increment and tell them the result. The second one blocks on
 * the first's row lock and comes back with the following number.
 *
 * **What this deliberately is not:**
 *
 *   MAX(project_number) + 1   forbidden by D-094. Two callers read the same max
 *                             and both write it, and the loser gets a unique
 *                             violation the user sees.
 *   COUNT(*) + 1              worse: also wrong after any archive or gap.
 *   SELECT then UPDATE        a transaction alone does **not** fix this. Under
 *                             READ COMMITTED — PostgreSQL's default — two
 *                             transactions can both read the same value before
 *                             either writes.
 *
 * **The same statement runs on PostgreSQL and SQLite**, verified on both before
 * this class was written: PostgreSQL supports `ON CONFLICT … DO UPDATE …
 * RETURNING`, and so does SQLite from 3.35 (the test connection runs 3.53). There
 * is therefore **one execution path and no semantic divergence** between the test
 * engine and the production engine — which matters, because a concurrency
 * strategy that only exists on one of them is a concurrency strategy nobody
 * tests. PostgreSQL remains authoritative, and the multi-process contention
 * evidence is taken there.
 *
 * **Gaps are expected.** A caller that allocates and then fails leaves its number
 * unused, and that is the correct trade: the alternatives are reusing references
 * or serializing every create behind one lock. Nothing may treat the sequence as
 * a record count (see {@see ProjectReference}).
 *
 * **The year comes from the application clock, never from input.** Not the
 * browser, not the request locale, not a user field, and never parsed back out of
 * an existing reference. `Date::now()` honours the configured application
 * timezone and is freezable in tests, so year rollover is provable rather than
 * hoped for.
 */
class AllocateProjectReference
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * The next reference for this Office, in the current application year.
     *
     * Returns the formatted string; the caller persists it on the Project. This
     * class writes nothing to `projects` — assignment is M3.3's job, and keeping
     * allocation separate is what lets it be tested without a Project existing.
     */
    public function forOffice(Office|string $office, ?int $year = null): string
    {
        $officeId = $office instanceof Office ? $office->getKey() : $office;
        $year ??= (int) Date::now()->format('Y');

        return ProjectReference::format($year, $this->nextValue($officeId, $year));
    }

    /**
     * The raw sequence value, allocated atomically.
     *
     * Exposed separately so a test can assert the integer without re-parsing the
     * formatted string — which nothing in the product may do.
     */
    public function nextValue(string $officeId, int $year): int
    {
        $now = Date::now();
        $table = 'project_reference_counters';

        // Written as one prepared statement rather than through the query
        // builder's upsert(), because we need the incremented value back and
        // `upsert()` returns an affected-row count. The SQL is identical on both
        // supported engines.
        $sql = <<<SQL
            INSERT INTO {$table} (office_id, reference_year, last_value, created_at, updated_at)
            VALUES (?, ?, 1, ?, ?)
            ON CONFLICT (office_id, reference_year)
            DO UPDATE SET last_value = {$table}.last_value + 1, updated_at = ?
            RETURNING last_value
            SQL;

        $timestamp = $now->toDateTimeString();

        $row = $this->connection->selectOne($sql, [$officeId, $year, $timestamp, $timestamp, $timestamp]);

        // Defensive: `RETURNING` on an upsert always produces a row on both
        // engines. If that ever stops being true, failing loudly beats handing
        // back a duplicate or a zero.
        if ($row === null || ! isset($row->last_value)) {
            throw new \RuntimeException(
                "Project reference allocation returned no value for office {$officeId} in {$year}."
            );
        }

        return (int) $row->last_value;
    }
}
