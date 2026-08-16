<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Project internal reference and the counter that allocates it (M3.2, D-094).
 *
 * `PRJ-YYYY-NNNNNN` — **ordinary office identification and nothing more**. It is
 * not a deed number, a repertorium number, a Matter or Warkah number, a
 * land/government registration number, or an entry in any legal register. The
 * `PRJ` prefix carries no Notary or PPAT legal meaning. D-094 says this and it is
 * repeated here because a column named `project_number` in a legal-office system
 * is exactly the sort of thing a future reader mistakes for a legal sequence.
 *
 * **`project_number` is nullable, deliberately.** The persistent development
 * `projects` table was inspected before this migration was written and held **0
 * rows**, so `NOT NULL` would have been safe as *data migration*. It is withheld
 * as a *design* choice: M3.2 ships no creation path that assigns a reference —
 * that is M3.3 — so a `NOT NULL` column would make Project unwritable for a whole
 * milestone, including by the M3.1 factory and tests. M3.3 assigns at creation
 * and may then consider tightening. Nothing is backfilled and nothing is
 * invented; a `MAX+1` backfill is forbidden outright by D-094.
 *
 * **Uniqueness is `(office_id, project_number)`, never global.** Each Office runs
 * its own annual sequence, so Office A and Office B may both legitimately hold
 * `PRJ-2026-000001`. A global unique index would make the second Office's first
 * project of the year fail for no reason anybody could explain. The namespace is
 * stable because Project Office is immutable (D-089).
 *
 * Under that composite index, multiple unassigned Projects per Office are fine:
 * both engines treat NULLs as distinct in a unique index, which is what lets the
 * column be nullable at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_reference_counters', function (Blueprint $table): void {
            // The allocation namespace, and the primary key. A natural composite
            // key rather than a ULID surrogate: this is allocator infrastructure,
            // not a business-domain entity, so there is nothing for a surrogate
            // id to identify that the namespace does not already. It follows the
            // same reasoning that made `party_id` both key and foreign key on the
            // subtype tables.
            //
            // The primary key is also the `UNIQUE (office_id, reference_year)`
            // invariant, and the index the atomic upsert conflicts against.
            $table->foreignUlid('office_id')->constrained('offices')->cascadeOnDelete();
            $table->unsignedSmallInteger('reference_year');

            // The last value handed out. The next allocation returns this + 1,
            // atomically, in one statement.
            $table->unsignedInteger('last_value')->default(0);

            $table->timestamps();

            $table->primary(['office_id', 'reference_year'], 'project_reference_counters_pkey');
        });

        Schema::table('projects', function (Blueprint $table): void {
            // Wide enough for the format and then some. `PRJ-2026-000001` is 15
            // characters; the column allows growth past 999 999 in one Office-year
            // rather than truncating or wrapping, which would be the two ways a
            // reference could silently stop being unique.
            $table->string('project_number', 32)->nullable()->after('office_id');

            // Per Office, never global. See the class docblock.
            $table->unique(['office_id', 'project_number'], 'projects_office_id_project_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique('projects_office_id_project_number_unique');
            $table->dropColumn('project_number');
        });

        Schema::dropIfExists('project_reference_counters');
    }
};
