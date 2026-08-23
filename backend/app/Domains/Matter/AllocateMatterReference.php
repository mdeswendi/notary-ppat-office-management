<?php

namespace App\Domains\Matter;

use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Office;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * Hands out the next Matter internal reference for an Office, year, and domain
 * (M4.3, D-103).
 *
 * **One statement, atomic, no read-then-write.**
 *
 * ```sql
 * INSERT INTO matter_reference_counters (office_id, reference_year, domain, last_value, ...)
 * VALUES (?, ?, ?, 1, ...)
 * ON CONFLICT (office_id, reference_year, domain)
 * DO UPDATE SET last_value = matter_reference_counters.last_value + 1, updated_at = ?
 * RETURNING last_value
 * ```
 *
 * The increment happens **inside the database**, in one statement, against a row
 * the engine locks for the duration of the upsert. Two concurrent callers cannot
 * both compute the same next value, because neither computes it at all — they ask
 * the database to increment and tell them the result. The second blocks on the
 * first's row lock and comes back with the following number.
 *
 * **What this deliberately is not:**
 *
 *   MAX(matter_number) + 1    forbidden by D-103. Two callers read the same max
 *                             and both write it, and the loser gets a unique
 *                             violation the user sees.
 *   COUNT(*) + 1              worse: also wrong after any gap.
 *   SELECT then UPDATE        a transaction alone does **not** fix this. Under
 *                             READ COMMITTED — PostgreSQL's default — two
 *                             transactions can both read the same value before
 *                             either writes.
 *
 * **Three namespace dimensions, not two.** Project counts per Office and year;
 * Matter adds the domain, because `N-` and `P-` are distinct prefixes and a shared
 * counter would make `N-2026-000001` and `P-2026-000001` compete for one value
 * (D-103). This is a **dedicated** counter table: the M3.2 Project allocator is
 * reused as a *pattern*, never as a table, because
 * `13_M3_PROJECT_ARCHITECTURE.md` section 9 refused to generalize it into
 * anything Matter-shaped and D-103 restates that refusal.
 *
 * **It opens no transaction of its own** and commits nothing, so it participates
 * in whatever transaction the caller already has. M4.4's `CreateMatter` will
 * allocate and insert inside one transaction, matching `CreateProject`. The
 * consequence, stated rather than hidden: the counter row stays locked from
 * allocation until that transaction ends, which serialises concurrent creates
 * *within one Office-year-domain* for the duration of a single insert. The
 * namespace split means Notary and PPAT creates never block each other.
 *
 * **Gaps are expected, and the distinction matters.** If allocation and insert
 * share a transaction that rolls back, the counter increment rolls back with it
 * and the number is not lost. If an allocation is **committed** and then not used,
 * the number is permanently skipped. Both are acceptable: this is an internal
 * identifier, not legal numbering, and nothing may treat the sequence as a record
 * count (see {@see MatterReference}).
 *
 * **The year comes from the application clock, never from input.** Not the
 * browser, not the request locale, not a user field, not a Matter or Project date,
 * and never parsed back out of an existing reference. `Date::now()` honours the
 * configured application timezone and is freezable in tests, so year rollover is
 * provable rather than hoped for. No Office-specific timezone semantics exist in
 * this repository and none is invented here.
 */
class AllocateMatterReference
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * The next reference for this Office and domain, in the current application
     * year.
     *
     * Returns the formatted string; the caller persists it on the Matter. This
     * class writes nothing to `matters` — assignment is M4.4's job, and keeping
     * allocation separate is what lets it be tested without a Matter existing.
     */
    public function forOffice(Office|string $office, MatterDomain $domain, ?int $year = null): string
    {
        $officeId = $office instanceof Office ? $office->getKey() : $office;
        $year ??= (int) Date::now()->format('Y');

        return MatterReference::format($domain, $year, $this->nextValue($officeId, $domain, $year));
    }

    /**
     * The raw sequence value, allocated atomically.
     *
     * Exposed separately so a test can assert the integer without re-parsing the
     * formatted string — which nothing in the product may do.
     */
    public function nextValue(string $officeId, MatterDomain $domain, int $year): int
    {
        $now = Date::now();
        $table = 'matter_reference_counters';

        // Written as one prepared statement rather than through the query
        // builder's upsert(), because we need the incremented value back and
        // `upsert()` returns an affected-row count. The SQL is identical on both
        // supported engines: PostgreSQL supports `ON CONFLICT … DO UPDATE …
        // RETURNING`, and so does SQLite from 3.35. One execution path, no
        // semantic divergence between the test engine and the production engine —
        // which matters, because a concurrency strategy that only exists on one of
        // them is a concurrency strategy nobody tests. PostgreSQL remains
        // authoritative, and the multi-process contention evidence is taken there.
        $sql = <<<SQL
            INSERT INTO {$table} (office_id, reference_year, domain, last_value, created_at, updated_at)
            VALUES (?, ?, ?, 1, ?, ?)
            ON CONFLICT (office_id, reference_year, domain)
            DO UPDATE SET last_value = {$table}.last_value + 1, updated_at = ?
            RETURNING last_value
            SQL;

        $timestamp = $now->toDateTimeString();

        $row = $this->connection->selectOne($sql, [
            $officeId, $year, $domain->value, $timestamp, $timestamp, $timestamp,
        ]);

        // Defensive: `RETURNING` on an upsert always produces a row on both
        // engines. If that ever stops being true, failing loudly beats handing
        // back a duplicate or a zero.
        if ($row === null || ! isset($row->last_value)) {
            throw new RuntimeException(
                "Matter reference allocation returned no value for office {$officeId}, "
                ."domain {$domain->value}, year {$year}."
            );
        }

        return (int) $row->last_value;
    }
}
