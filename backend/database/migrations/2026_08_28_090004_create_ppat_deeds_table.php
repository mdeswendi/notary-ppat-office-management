<?php

use App\Domains\Ppat\Enums\PpatDeedStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PPAT Deed (M7.1, D-121).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 18, with **three omissions the M7
 * brief would have added** and one support key. Nothing else is added.
 *
 * ## One document pointer, not three
 *
 * `notary_deeds` carries `draft_document_id`, `final_document_id` and
 * `minuta_document_id`; `ppat_deeds` carries **only `final_document_id`**, and that
 * is the canonical field list rather than an oversight. **PPAT's supporting material
 * is the Warkah**, which is its own table with its own document links — the structural
 * counterpart of Notary's Minuta. Adding a `draft_document_id` or a
 * `minuta_document_id` here by analogy with Notary would extend the canonical list on
 * this milestone's authority, and a Minuta pointer would be worse than redundant: it
 * would suggest PPAT deeds have Minuta Akta, which is a Notary instrument.
 *
 * ## `locked_by` and `deleted_at` are absent
 *
 * The M6.1 rulings, on the same sources. `locked_at` appears in the canonical list and
 * `locked_by` does not; adding an actor would assert that somebody performs a locking
 * act, which is open question nine. `deleted_at` is omitted by the ERD, section 33
 * prefers states over destructive deletion for finalized legal records, `CLAUDE.md`
 * section 30 forbids user-facing hard delete of Deeds, and **no `ppat.deeds.delete`
 * capability exists** — verified against the live registry.
 *
 * ## The status vocabulary is a decision, not a transcription
 *
 * **`ppat_deeds` has no status vocabulary in the ERD**, where `notary_deeds` lists
 * six. M7 adopts the same six on `CLAUDE.md` section 29's authority — it states
 * `DRAFT → UNDER_REVIEW → APPROVED → FINALIZED → LOCKED` as the legal-record lifecycle
 * generally — so the two domains answer the same question the same way.
 *
 * This is worth flagging in the schema itself because it changes what a later
 * milestone may assume: a canonical PPAT status list, if one turns up, must be
 * reconciled with this rather than treated as confirming it. See
 * {@see PpatDeedStatus}.
 *
 * `VOID` and `SUPERSEDED` are storable and reached by nothing (D-121, O-039).
 *
 * ## `deed_type_code` is not constrained
 *
 * The ERD calls AJB, APHT, HIBAH and the rest *"**Possible** deed codes"*. AJB and
 * APHT are fixed legal terminology (`05_I18N_LEGAL_TERMINOLOGY.md`) and are what an
 * office will type, but a CHECK would assert PPAT has six deed types.
 *
 * ## Office ownership is structural on every reference
 *
 * `RESTRICT` everywhere and `SET NULL` nowhere. Nulling a composite key nulls *both*
 * its columns including `office_id`, which is `NOT NULL` — so the obvious
 * `nullOnDelete()` on `(final_document_id, office_id)` is not merely stylistically
 * wrong, it would fail at runtime (the M5.4 finding).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppat_deeds', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // Required: a deed is always the output of a Matter. The domain is not
            // stored again — `matters.domain` is the one discriminator, and a second
            // copy is a second thing that can disagree.
            $table->ulid('matter_id');

            // Nullable, unique per Office where present, office-supplied, no format
            // validated — the D-120 ruling for Notary, and for identical reasons.
            // Written through `ppat.deeds.number` at M7.2.
            $table->string('deed_number', 100)->nullable();
            $table->date('deed_date')->nullable();

            // Open list — see the class docblock.
            $table->string('deed_type_code', 50)->nullable();

            $table->string('title');

            $table->string('status', 20)->default(PpatDeedStatus::DRAFT->value);

            // **The only document pointer the ERD gives this table.**
            $table->ulid('final_document_id')->nullable();

            // Each act records when and by whom, as a pair. `locked_at` has no
            // partner — see the class docblock.
            $table->timestamp('reviewed_at')->nullable();
            $table->ulid('reviewed_by')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();

            $table->timestamp('finalized_at')->nullable();
            $table->ulid('finalized_by')->nullable();

            // Canonical column, written by nothing in M7.
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            // Plain keys...
            $table->foreign('matter_id', 'ppat_deeds_matter_foreign')
                ->references('id')->on('matters')->restrictOnDelete();

            $table->foreign('final_document_id', 'ppat_deeds_final_document_foreign')
                ->references('id')->on('documents')->restrictOnDelete();

            // ...and the composite keys that make Office agreement structural.
            $table->foreign(['matter_id', 'office_id'], 'ppat_deeds_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();

            $table->foreign(['final_document_id', 'office_id'], 'ppat_deeds_final_document_office_foreign')
                ->references(['id', 'office_id'])->on('documents')->restrictOnDelete();

            foreach ([
                'reviewed_by' => 'ppat_deeds_reviewed_by_office_foreign',
                'approved_by' => 'ppat_deeds_approved_by_office_foreign',
                'finalized_by' => 'ppat_deeds_finalized_by_office_foreign',
            ] as $column => $name) {
                $table->foreign([$column, 'office_id'], $name)
                    ->references(['id', 'office_id'])->on('users')->restrictOnDelete();
            }

            // A legal number is unique within the office that issued it. NULLs are
            // distinct on both connections, so unnumbered drafts coexist freely.
            $table->unique(['office_id', 'deed_number'], 'ppat_deeds_office_number_unique');

            // The support key `ppat_warkah` needs, created here rather than by a
            // later ALTER — the correction M6.3 had to make.
            $table->unique(['id', 'office_id'], 'ppat_deeds_id_office_id_unique');

            $table->index(['office_id', 'status'], 'ppat_deeds_office_status_index');
            $table->index('matter_id', 'ppat_deeds_matter_index');
            $table->index('deed_date', 'ppat_deeds_deed_date_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $statuses = implode("', '", PpatDeedStatus::values());

            $connection->statement(
                "ALTER TABLE ppat_deeds ADD CONSTRAINT ppat_deeds_status_check CHECK (status IN ('{$statuses}'))"
            );

            // **No `deed_type_code` CHECK** — the ERD calls those codes possible.

            // Each act is recorded as a pair or not at all — the
            // `tasks_completion_pair_check` reasoning (D-119), applied three times.
            foreach (['reviewed', 'approved', 'finalized'] as $act) {
                $connection->statement(
                    "ALTER TABLE ppat_deeds ADD CONSTRAINT ppat_deeds_{$act}_pair_check "
                    ."CHECK (({$act}_at IS NULL AND {$act}_by IS NULL) "
                    ."OR ({$act}_at IS NOT NULL AND {$act}_by IS NOT NULL))"
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ppat_deeds');
    }
};
