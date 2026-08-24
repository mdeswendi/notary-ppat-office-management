<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Notary Matter extension (M6.1, D-120).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 10, with **one addition**, recorded
 * rather than made quietly.
 *
 * ## This is an extension, not a second Matter root
 *
 * `matters` is the one root, carrying its canonical `domain` discriminator since
 * M4.2. This table holds only what is true of a Matter *because* it is a Notary
 * Matter. So `matter_id` **is the primary key** rather than a foreign key beside a
 * surrogate ULID: there is exactly one extension row per Matter, and a second
 * identifier would be a second way to name the same thing.
 *
 * `03_DATABASE_ERD.md` line 768 records that M4 deliberately persisted **nothing**
 * standing in for these columns (D-102) — not `deed_category`, not
 * `requires_minuta`, not `requires_register_entry` — because every one of them is
 * *"domain-semantic and unvalidated"*, and D-095 rules that a column added on
 * speculation is one somebody fills in wrongly. M6 is the milestone the ERD assigns
 * them to, so M6 may persist them.
 *
 * ## Persisting a flag is not the same act as branching on it
 *
 * **`requires_register_entry` triggers nothing**, and that is the point worth
 * stating plainly. The M6 brief asked that finalizing a deed automatically create a
 * Repertorium entry when this flag is true. *"What is the correct Repertorium entry
 * procedure and period?"* is open question two in `08_NOTARY_WORKFLOW.md` section 6,
 * and there is no register table in M6 to create an entry in — registers are batch
 * 11 (section 32), two batches after this one.
 *
 * The same holds for `requires_minuta`: it records the office's own classification
 * of the Matter. What the office does with that classification is theirs until a
 * domain source describes it.
 *
 * **`deed_category` stays opaque and nullable**, exactly as `document_type_code` did
 * at M5.1 (D-116). The ERD gives it no vocabulary, and the examples elsewhere in the
 * canonical set are prose rather than a catalogue.
 *
 * ## The addition
 *
 * **`office_id` is not in the canonical field list.** It is added because the
 * composite foreign key that makes Office agreement structural needs a carrier in
 * this table to resolve through — the construction `company_people` (D-080),
 * `project_parties` (D-098), `matters` (D-107), the document junctions (D-116) and
 * `tasks` (D-119) all use. *(Contrast `task_comments`, which correctly carries none:
 * it reaches its Office through its task in one join and needs no composite key of
 * its own.)*
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notary_matters', function (Blueprint $table): void {
            // One row per Matter, named by the Matter. No surrogate key.
            $table->ulid('matter_id')->primary();

            // The composite carrier. See the class docblock.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // Opaque and nullable — the ERD names no vocabulary (D-116's ruling
            // for `document_type_code`, applied unchanged).
            $table->string('deed_category', 50)->nullable();

            // Stored and read; nothing branches on either. See the class docblock.
            $table->boolean('requires_minuta')->default(true);
            $table->boolean('requires_register_entry')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();

            // **One key, doing both jobs.** Both columns are NOT NULL here, so the
            // composite alone guarantees the Matter exists *and* that this row
            // agrees with it about the Office. A separate plain key on `matter_id`
            // would be redundant rather than defensive.
            //
            // **CASCADE, unlike almost everything else in this schema.** An
            // extension row is not a legal record in its own right — it is
            // classification *of* a Matter, meaningless without one, and must not
            // outlive it. The same reasoning `task_comments.task_id` used at M5.4.
            // Nothing in the product deletes a Matter today (D-102), so this is a
            // structural guarantee rather than a live code path.
            $table->foreign(['matter_id', 'office_id'], 'notary_matters_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notary_matters');
    }
};
