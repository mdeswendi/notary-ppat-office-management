<?php

use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Matter internal reference and the counter that allocates it (M4.3, D-103).
 *
 * `N-YYYY-NNNNNN` for Notary and `P-YYYY-NNNNNN` for PPAT — **ordinary office
 * identification and nothing more**. Not a deed number, a repertorium number, a
 * minuta or Warkah number, a PPAT register entry, a land or government
 * registration number, or an entry in any legal register. The `N` and `P`
 * prefixes carry no Notary or PPAT legal meaning. D-103 says this, and it is
 * repeated here because a column named `matter_number` in a legal-office system is
 * exactly the sort of thing a future reader mistakes for a legal sequence.
 *
 * **`matter_number` is nullable, deliberately.** The persistent development
 * database was inspected before this migration was written and holds **no
 * `matters` table at all** — it is still at 22 migrations, so no Matter row has
 * ever existed outside an in-memory test database or a disposable verification
 * database. `NOT NULL` would therefore have been safe as *data migration*. It is
 * withheld as a *design* choice, exactly as M3.2 withheld it for Project: M4.3
 * ships no creation path that assigns a reference — that is M4.4 — so a `NOT NULL`
 * column would make Matter unwritable for a whole milestone, including by the M4.2
 * factory and its tests. **Nothing is backfilled and nothing is invented**; a
 * `MAX+1` backfill is forbidden outright by D-103.
 *
 * **Uniqueness is `(office_id, matter_number)`, never global.** Each Office runs
 * its own annual sequence per domain, so Office A and Office B may both
 * legitimately hold `N-2026-000001`. A global unique index would make the second
 * Office's first Matter of the year fail for no reason anybody could explain.
 *
 * **`domain` is deliberately absent from that unique key.** The formatted string
 * already begins with `N-` or `P-`, so a Notary and a PPAT reference in one
 * Office-year can never collide as strings. Adding `domain` would widen the index
 * without excluding anything, and would additionally permit `N-2026-000001` to
 * exist twice in one Office if the domains differed — which the prefix makes
 * nonsense. The domain belongs in the **counter's** key, where it separates
 * sequences.
 *
 * Under that composite index, multiple unallocated Matters per Office are fine:
 * both engines treat NULLs as distinct in a unique index, which is what lets the
 * column be nullable at all.
 *
 * **The generic numbering engine `03_DATABASE_ERD.md` section 27 sketches is
 * deliberately not used.** No `numbering_sequences`, no configurable prefix
 * pattern, no monthly reset, no user-editable format. That table belongs to
 * `master.numbering.*` and a milestone that owns it; Matter uses the dedicated
 * allocator D-103 locks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_reference_counters', function (Blueprint $table): void {
            // The allocation namespace, and the primary key. A natural composite
            // key rather than a ULID surrogate: this is allocator infrastructure,
            // not a business-domain entity, so there is nothing for a surrogate id
            // to identify that the namespace does not already. The same reasoning
            // `project_reference_counters` used.
            //
            // The primary key is also the uniqueness invariant and the index the
            // atomic upsert conflicts against.
            //
            // `cascadeOnDelete` rather than the RESTRICT used across the business
            // tables, following the Project counter: a counter row is
            // infrastructure, not work, and there is nothing to preserve once its
            // Office is gone.
            $table->foreignUlid('office_id')->constrained('offices')->cascadeOnDelete();
            $table->unsignedSmallInteger('reference_year');

            // Three dimensions, not two: `N-` and `P-` are distinct prefixes, so a
            // shared counter would make them compete for one value (D-103).
            $table->string('domain', 20);

            // The last value handed out. The next allocation returns this + 1,
            // atomically, in one statement.
            $table->unsignedInteger('last_value')->default(0);

            $table->timestamps();

            $table->primary(
                ['office_id', 'reference_year', 'domain'],
                'matter_reference_counters_pkey'
            );
        });

        Schema::table('matters', function (Blueprint $table): void {
            // Wide enough for the format and then some. `N-2026-000001` is 13
            // characters; the column allows growth past 999 999 in one
            // Office-year-domain rather than truncating or wrapping, which would be
            // the two ways a reference could silently stop being unique.
            $table->string('matter_number', 32)->nullable()->after('office_id');

            // Per Office, never global, and without `domain`. See the class note.
            $table->unique(['office_id', 'matter_number'], 'matters_office_id_matter_number_unique');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $notary = MatterReference::prefix(MatterDomain::NOTARY);
            $ppat = MatterReference::prefix(MatterDomain::PPAT);

            // **The belt-and-braces domain-prefix invariant, and only that.** A
            // NOTARY Matter may not carry a `P-` reference and a PPAT Matter may
            // not carry an `N-` one. Full format correctness — the year, the
            // padding, the digit growth — stays in `MatterReference`, which is the
            // only thing that ever constructs a reference; turning PostgreSQL into
            // a second parser would duplicate that rule in a language where it is
            // harder to read and harder to change.
            //
            // Null-aware: `matter_number` is nullable in M4.3, and an unallocated
            // Matter must stay valid.
            $connection->statement(
                'ALTER TABLE matters ADD CONSTRAINT matters_number_domain_prefix_check CHECK ('
                .'matter_number IS NULL'
                ." OR (domain = '".MatterDomain::NOTARY->value."' AND matter_number LIKE '{$notary}-%')"
                ." OR (domain = '".MatterDomain::PPAT->value."' AND matter_number LIKE '{$ppat}-%')"
                .')'
            );

            // The counter's own invariants, stated in the language that actually
            // enforces them. `unsignedSmallInteger` and `unsignedInteger` are
            // MySQL concepts: PostgreSQL has no unsigned integer type and silently
            // maps both to signed columns, so without these CHECKs a negative year
            // or a negative counter would be accepted. The same lesson M4.1
            // learned from `default_duration_days`, applied before it could bite.
            $domains = implode("', '", MatterDomain::values());

            $connection->statement(
                "ALTER TABLE matter_reference_counters ADD CONSTRAINT matter_reference_counters_domain_check CHECK (domain IN ('{$domains}'))"
            );

            $connection->statement(
                'ALTER TABLE matter_reference_counters ADD CONSTRAINT matter_reference_counters_year_check '
                .'CHECK (reference_year >= 0)'
            );

            $connection->statement(
                'ALTER TABLE matter_reference_counters ADD CONSTRAINT matter_reference_counters_last_value_check '
                .'CHECK (last_value >= 0)'
            );
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs there.
        // `MatterReference` is what refuses a wrong prefix on that connection —
        // stated plainly rather than left to be discovered, exactly as the
        // `matters`, `service_types`, and `projects` migrations say of their own
        // coded columns.
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('ALTER TABLE matters DROP CONSTRAINT IF EXISTS matters_number_domain_prefix_check');
        }

        Schema::table('matters', function (Blueprint $table): void {
            $table->dropUnique('matters_office_id_matter_number_unique');
            $table->dropColumn('matter_number');
        });

        Schema::dropIfExists('matter_reference_counters');
    }
};
