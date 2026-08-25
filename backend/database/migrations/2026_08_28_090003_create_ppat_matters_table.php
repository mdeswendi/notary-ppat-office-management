<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PPAT Matter extension (M7.1, D-121).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 10, with `office_id` added as the
 * composite carrier. The mirror of `notary_matters` (M6.1), down to the reasoning.
 *
 * **An extension, not a second Matter root.** `matters` is the one root and carries
 * the canonical `domain` discriminator (M4.2), so `matter_id` **is** the primary key
 * — one extension row per Matter, and a surrogate would be a second way to name the
 * same Matter.
 *
 * ## The three flags are stored and branch on nothing
 *
 * `03_DATABASE_ERD.md` line 770 records that M4 deliberately persisted **nothing**
 * standing in for `land_office_region`, `tax_processing_required` or
 * `registration_required`, because every one of them is *"domain-semantic and
 * unvalidated"*. M7 is the milestone the ERD assigns them to, so M7 may persist
 * them — and **persisting a flag is not the same act as branching on it.**
 *
 * Specifically:
 *
 * - **`registration_required` triggers nothing.** No register entry is created by
 *   any code path; `ppat_register_entries` is batch 11 and the register format is
 *   open question six (D-121, O-042). This is the same refusal M6 made twice for
 *   `notary_matters.requires_register_entry`.
 * - **`tax_processing_required` triggers nothing.** `ppat_tax_records` is not built
 *   at all — it has no canonical capability (O-040).
 * - **`land_office_region` is free text.** Which land office serves which region is
 *   administrative geography nobody here may encode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppat_matters', function (Blueprint $table): void {
            // One row per Matter, named by the Matter. No surrogate key.
            $table->ulid('matter_id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // Free text. See the class docblock.
            $table->string('land_office_region')->nullable();

            // Stored, read, and branched on by nothing.
            $table->boolean('tax_processing_required')->default(true);
            $table->boolean('registration_required')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();

            // **One key doing both jobs.** Both columns are NOT NULL here, so the
            // composite alone guarantees the Matter exists and that this row agrees
            // with it about the Office.
            //
            // **CASCADE**, as `notary_matters` has: an extension row is
            // classification *of* a Matter, meaningless without one, and must not
            // outlive it. Nothing in the product deletes a Matter (D-102), so this is
            // a structural guarantee rather than a live path.
            $table->foreign(['matter_id', 'office_id'], 'ppat_matters_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppat_matters');
    }
};
