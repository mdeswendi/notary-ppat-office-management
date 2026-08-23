<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The atomic allocator behind `DOC-YYYY-NNNNNN` (M5.1, D-116).
 *
 * The M3.2 and M4.3 allocator **reused as a pattern, never as a table**. Project
 * counts per Office and year; Matter adds the domain because `N-` and `P-` are
 * distinct sequences; Document needs neither a domain nor anything else, so it
 * gets the two-dimensional key Project has — a dedicated table, not a generalized
 * one. `03_DATABASE_ERD.md` section 27 sketches a configurable numbering engine
 * with prefix patterns and monthly resets; it is deliberately not used, exactly
 * as D-108 declined it.
 *
 * **One atomic statement, no read-then-write:**
 *
 * ```sql
 * INSERT ... ON CONFLICT (office_id, reference_year)
 * DO UPDATE SET last_value = last_value + 1 RETURNING last_value
 * ```
 *
 * The increment happens inside the database against a row the engine locks for
 * the duration of the upsert, so two concurrent callers cannot both compute the
 * same value — neither computes it at all. `MAX+1`, `COUNT+1`, `latest()+1` and
 * read-then-write are forbidden, and a transaction alone would not fix a
 * `SELECT`-then-`UPDATE`: under `READ COMMITTED` two transactions can both read
 * before either writes.
 *
 * Identical SQL on PostgreSQL and SQLite 3.35+, so there is one execution path.
 *
 * **`office_id` cascades**, following both earlier counters: a counter row is
 * allocator infrastructure rather than work, and `documents` separately restricts
 * Office deletion.
 *
 * **The natural composite primary key, no ULID surrogate** — there is nothing for
 * a surrogate to identify that the namespace does not already, and this is
 * infrastructure rather than a business-domain entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_reference_counters', function (Blueprint $table): void {
            $table->foreignUlid('office_id')
                ->constrained('offices')
                ->cascadeOnDelete();

            // Signed on PostgreSQL whatever the type name says, so the CHECKs
            // below are what actually enforce what the schema claims — the M4.1
            // lesson, applied for the fourth time.
            $table->unsignedSmallInteger('reference_year');

            $table->unsignedInteger('last_value')->default(0);

            $table->timestamps();

            // Also the `UNIQUE (office_id, reference_year)` the atomic upsert
            // conflicts against.
            $table->primary(['office_id', 'reference_year']);
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement(
                'ALTER TABLE document_reference_counters ADD CONSTRAINT document_reference_counters_year_check '
                .'CHECK (reference_year >= 0)'
            );

            $connection->statement(
                'ALTER TABLE document_reference_counters ADD CONSTRAINT document_reference_counters_last_value_check '
                .'CHECK (last_value >= 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reference_counters');
    }
};
