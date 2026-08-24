<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minuta Akta — where the original deed record is filed (M6.3, D-120).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 17, with **one addition** and
 * **nothing invented**. The M6.0 lock's section 8.5 ruled every question this table
 * raises; this migration implements that ruling rather than deciding again.
 *
 * ## A Minuta is not a file, and not a deed either
 *
 * The *file* lives on a Document and its immutable versions (M5.1); `document_id`
 * points at it. What this table adds is what the Document cannot know: **which
 * shelf the physical original sits on**. `archive_location`, `volume_number` and
 * `bundle_number` describe a filing cabinet, which is why they are free text —
 * inventing a structure for them would be inventing the office's filing system.
 *
 * `notary_deeds.minuta_document_id` already points at the same kind of thing, and
 * the two are not redundant: that column answers *which file*, this table answers
 * *which shelf, and under what reference*.
 *
 * ## One Minuta per Deed
 *
 * `UNIQUE (notary_deed_id)`. The ERD states no cardinality; this is an engineering
 * decision and it is the conservative one, because **the term itself carries it** —
 * a Minuta Akta is the original record of *one* deed. If an office needs a second,
 * that is a rule to state, not an index to drop quietly.
 *
 * ## `release_status` is created, and no vocabulary is asserted
 *
 * The ERD names the column and gives it **no values at all**. The `DRAFT`,
 * `ARCHIVED`, `RELEASED` triple that milestone briefs keep proposing appears in no
 * canonical document — and *"What triggers Minuta Akta archiving, and what release
 * conditions apply?"* is open question four in `08_NOTARY_WORKFLOW.md` section 6.
 *
 * So the column is **nullable, with no default and no CHECK constraint.** Defaulting
 * it to `DRAFT` would assert a vocabulary; constraining it to three values would
 * assert the whole lifecycle. It exists because the ERD names it, and it stays empty
 * because nothing may write it yet. `notary.minuta.archive` and
 * `notary.minuta.release` remain registered and unimplemented (D-064).
 *
 * `archived_at` and `archived_by` are the same: canonical columns, written by
 * nothing, kept honest by a pair CHECK so that if a later milestone does write them
 * it cannot write half of one.
 *
 * ## The addition
 *
 * **`office_id` is not in the canonical field list.** It is added because the three
 * composite foreign keys below need a carrier in this table to resolve through —
 * the construction `company_people` (D-080), `matters` (D-107), the document
 * junctions (D-116), `tasks` (D-119) and `notary_matters` (M6.1) all use.
 *
 * ## No `deleted_at`
 *
 * The ERD omits it, and **there is no `notary.minuta.delete` capability** — verified
 * against the live registry, which carries `view`, `create`, `update`, `archive` and
 * `release` and no sixth code. `03_DATABASE_ERD.md` section 33 prefers states over
 * destructive deletion for records of this kind. A Minuta filed against the wrong
 * deed is a correction, and correction mechanisms are open question five.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notary_minuta', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The composite carrier and the OFFICE scope predicate.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // One per deed — see the class docblock. The unique index doubles as
            // the lookup index for `GET /notary/deeds/{deed}/minuta`.
            $table->ulid('notary_deed_id');

            // The file. A Document, never a version: the Document carries its own
            // current-version pointer (D-116), so the history behind it stays whole.
            $table->ulid('document_id')->index();

            // Free text. They describe a physical shelf.
            $table->string('archive_location')->nullable();
            $table->string('volume_number', 50)->nullable();
            $table->string('bundle_number', 50)->nullable();

            // Canonical column, no vocabulary, no default, no CHECK. See the class
            // docblock — this is the one field a brief keeps trying to fill in.
            $table->string('release_status', 30)->nullable();

            // Canonical columns nothing writes in M6. The pair CHECK below keeps
            // them honest for whichever milestone eventually does.
            $table->timestamp('archived_at')->nullable();
            $table->ulid('archived_by')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // No `deleted_at` — see the class docblock.

            // Plain keys so a row cannot point at a nonexistent record...
            $table->foreign('notary_deed_id', 'notary_minuta_deed_foreign')
                ->references('id')->on('notary_deeds')->restrictOnDelete();

            $table->foreign('document_id', 'notary_minuta_document_foreign')
                ->references('id')->on('documents')->restrictOnDelete();

            // ...and the composite keys that make Office agreement structural.
            // Every one resolves through this table's own `office_id`, so none of
            // them can disagree with another.
            $table->foreign(['notary_deed_id', 'office_id'], 'notary_minuta_deed_office_foreign')
                ->references(['id', 'office_id'])->on('notary_deeds')->restrictOnDelete();

            $table->foreign(['document_id', 'office_id'], 'notary_minuta_document_office_foreign')
                ->references(['id', 'office_id'])->on('documents')->restrictOnDelete();

            $table->foreign(['archived_by', 'office_id'], 'notary_minuta_archived_by_office_foreign')
                ->references(['id', 'office_id'])->on('users')->restrictOnDelete();

            $table->unique('notary_deed_id', 'notary_minuta_deed_unique');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            // Half an archival is a row nobody can explain — the
            // `tasks_completion_pair_check` reasoning (D-119), applied to a pair
            // that nothing writes yet precisely so it cannot be written wrongly
            // later.
            $connection->statement(
                'ALTER TABLE notary_minuta ADD CONSTRAINT notary_minuta_archived_pair_check '
                .'CHECK ((archived_at IS NULL AND archived_by IS NULL) '
                .'OR (archived_at IS NOT NULL AND archived_by IS NOT NULL))'
            );

            // **No `release_status` CHECK**, deliberately. Constraining it would
            // assert a vocabulary no canonical document defines.
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs there.
        // The model guard holds the pair rule on that connection.
    }

    public function down(): void
    {
        Schema::dropIfExists('notary_minuta');
    }
};
