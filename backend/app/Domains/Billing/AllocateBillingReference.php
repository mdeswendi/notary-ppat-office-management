<?php

namespace App\Domains\Billing;

use App\Models\Office;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * The next quotation or invoice reference, allocated atomically (M8.2, D-124).
 *
 * The fourth allocator, after Project (D-103), Matter and Document (D-108), and
 * built the same way for the same reason: `CLAUDE.md` section 38 and
 * `03_DATABASE_ERD.md` section 27 both forbid `MAX + 1`, which hands two people
 * the same number the moment they create an invoice in the same second.
 *
 * ```sql
 * INSERT INTO billing_reference_counters (office_id, code, reference_year, last_value, ...)
 * VALUES (?, ?, ?, 1, ...)
 * ON CONFLICT (office_id, code, reference_year)
 * DO UPDATE SET last_value = billing_reference_counters.last_value + 1, updated_at = ?
 * RETURNING last_value
 * ```
 *
 * **One statement, and the same statement on both engines.** PostgreSQL supports
 * `ON CONFLICT … DO UPDATE … RETURNING`, and so does SQLite from 3.35, so the
 * concurrency strategy the tests exercise is the one production runs. The
 * increment happens inside the database, so two concurrent callers serialise on
 * the row rather than racing between a read and a write.
 *
 * **Namespaced by Office, sequence code, and calendar year.** Three dimensions
 * where Project and Document use two, because this counter serves two sequences.
 * `matter_reference_counters` set that precedent with its `domain` column.
 */
class AllocateBillingReference
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * The next reference for this Office, sequence and year, formatted.
     */
    public function forOffice(string $code, Office|string $office, ?int $year = null): string
    {
        $officeId = $office instanceof Office ? $office->getKey() : $office;
        $year ??= (int) Date::now()->format('Y');

        return BillingReference::format($code, $year, $this->nextValue($code, $officeId, $year));
    }

    /**
     * The raw sequence value.
     *
     * Exposed so a test can assert the number itself rather than re-parsing a
     * formatted string — which is the one thing {@see BillingReference} refuses
     * to do.
     */
    public function nextValue(string $code, string $officeId, int $year): int
    {
        $table = 'billing_reference_counters';
        $timestamp = Date::now()->toDateTimeString();

        $sql = <<<SQL
            INSERT INTO {$table} (office_id, code, reference_year, last_value, created_at, updated_at)
            VALUES (?, ?, ?, 1, ?, ?)
            ON CONFLICT (office_id, code, reference_year)
            DO UPDATE SET last_value = {$table}.last_value + 1, updated_at = ?
            RETURNING last_value
            SQL;

        $row = $this->connection->selectOne($sql, [
            $officeId, $code, $year, $timestamp, $timestamp, $timestamp,
        ]);

        // Defensive: `RETURNING` on an upsert always produces a row on both
        // engines. If that ever stops being true, failing loudly beats handing
        // back a reference nobody allocated.
        if ($row === null || ! isset($row->last_value)) {
            throw new RuntimeException(
                "The billing reference allocator returned no value for [{$code}]. "
                .'Refusing to invent one: a guessed reference is the MAX+1 failure this class exists to prevent.'
            );
        }

        return (int) $row->last_value;
    }
}
