<?php

namespace App\Domains\Document;

use App\Models\Office;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * Allocate the next internal document reference for an Office and year
 * (M5.1, D-116).
 *
 * ```text
 * DOC-YYYY-NNNNNN
 * ```
 *
 * **Ordinary office identification and nothing more** — not a deed number, not a
 * repertorium entry, not a minuta or Warkah number, not a land or government
 * registration number. The `DOC` prefix carries no legal meaning, and
 * `CLAUDE.md` section 38 is explicit that internal identifiers and legal deed
 * numbers are different concepts.
 *
 * **Two namespace dimensions: Office + calendar year.** Matter needed a third
 * because `N-` and `P-` are distinct sequences that would otherwise compete for
 * one value (D-108); a Document has no such split, so it takes the shape Project
 * uses. The M3.2 and M4.3 allocators are reused as a **pattern, never as a
 * table** — `03_DATABASE_ERD.md` section 27's configurable numbering engine, with
 * prefix patterns and monthly resets, is deliberately not used.
 *
 * **One atomic statement, no read-then-write.** The increment happens inside the
 * database against a row the engine locks for the duration of the upsert, so two
 * concurrent callers cannot both compute the same value — neither computes it at
 * all. `MAX+1`, `COUNT+1`, `latest()+1` and read-then-write are forbidden, and a
 * transaction alone would not fix a `SELECT`-then-`UPDATE`: under `READ
 * COMMITTED` two transactions can both read before either writes.
 *
 * **This class opens no transaction of its own** and commits nothing, so it
 * participates in the caller's. The milestone that builds upload will allocate
 * and insert inside one transaction, matching `CreateMatter`. The consequence,
 * stated rather than hidden: the counter row stays locked from allocation until
 * that transaction ends, serialising concurrent creates *within one Office-year*
 * for the duration of a single insert.
 *
 * **Gaps are acceptable, and the distinction is precise.** If allocation and
 * insert share a transaction that rolls back, the increment rolls back with it
 * and the number is **not** lost. If an allocation **commits** and is then not
 * used, the number is permanently skipped. Nothing may treat the sequence as a
 * document count, and sequential appearance carries no legal weight.
 *
 * **The year comes from the application clock**, never from a request body, a
 * browser, a document's own date, or a value parsed back out of an existing
 * reference. No Office-timezone semantics are invented: `offices.timezone` exists
 * and no code reads it, and doing so here would create a concept the repository
 * does not have.
 */
class AllocateDocumentReference
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * The next reference for this Office and year, formatted.
     */
    public function forOffice(Office|string $office, ?int $year = null): string
    {
        $officeId = $office instanceof Office ? $office->getKey() : $office;
        $year ??= (int) Date::now()->format('Y');

        return DocumentReference::format($year, $this->nextValue($officeId, $year));
    }

    /**
     * The raw sequence value, exposed so a test can assert the number itself
     * rather than re-parsing a formatted string — which is the one thing
     * {@see DocumentReference} refuses to do.
     */
    public function nextValue(string $officeId, int $year): int
    {
        $table = 'document_reference_counters';
        $timestamp = Date::now()->toDateTimeString();

        // One prepared statement rather than the query builder's `upsert()`,
        // because the incremented value is needed back and `upsert()` returns an
        // affected-row count. The SQL is identical on both supported engines:
        // PostgreSQL supports `ON CONFLICT … DO UPDATE … RETURNING`, and so does
        // SQLite from 3.35. One execution path, so the concurrency strategy the
        // tests exercise is the one production runs.
        $sql = <<<SQL
            INSERT INTO {$table} (office_id, reference_year, last_value, created_at, updated_at)
            VALUES (?, ?, 1, ?, ?)
            ON CONFLICT (office_id, reference_year)
            DO UPDATE SET last_value = {$table}.last_value + 1, updated_at = ?
            RETURNING last_value
            SQL;

        $row = $this->connection->selectOne($sql, [
            $officeId, $year, $timestamp, $timestamp, $timestamp,
        ]);

        // Defensive: `RETURNING` on an upsert always produces a row on both
        // engines. If that ever stops being true, failing loudly beats handing
        // back a reference nobody allocated.
        if ($row === null || ! isset($row->last_value)) {
            throw new RuntimeException(
                'The document reference allocator returned no value. '
                .'Refusing to invent one: a guessed reference is the MAX+1 failure this class exists to prevent.'
            );
        }

        return (int) $row->last_value;
    }
}
