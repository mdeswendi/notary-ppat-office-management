<?php

use App\Domains\Notary\Enums\NotaryDeedStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Notarial Deed — Akta Notaris (M6.1, D-120).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 17, with **three omissions**, each
 * decided rather than drifted into. Nothing is added.
 *
 * ## A Deed is not a Document
 *
 * The Deed is the legal record: its number, its date, its state, who reviewed and
 * approved it. The *file* lives on a Document and its immutable versions (M5.1).
 * The three `*_document_id` columns point at Documents; no bytes live here. That is
 * the separation the M5 lock drew between a Document and a file, applied one level
 * up.
 *
 * ## `deed_number` — the shape, without the rule
 *
 * **Nullable, office-unique where present, supplied by the office, and validated
 * against no format.**
 *
 * *"What are the deed numbering rules, and who assigns the number?"* is open
 * question one in `08_NOTARY_WORKFLOW.md` section 6, and `CLAUDE.md` section 62
 * names deed numbering rules explicitly among the things not to invent. So:
 *
 * - **No format.** `{deed_type_code}/{register_number}/{year}` is the M6 brief's
 *   proposal and is precisely the rule that may not be invented here.
 * - **No allocator.** D-103 already ruled that the Matter allocator's
 *   `N-YYYY-NNNNNN` is *"an operational identifier, never a legal deed number"* —
 *   *"not a deed number, a repertorium number, a minuta or Warkah number"*. Reusing
 *   it would be exactly the conflation D-103 and D-108 exist to prevent.
 * - **Nullable**, following `document_number` at M5.1 and `matter_number` at M4.2:
 *   no creation path allocates one, and requiring it would assert the number exists
 *   before the deed does — half of the open question.
 * - Written through **`notary.deeds.number`**, its own canonical capability, rather
 *   than folded into finalization, which would assert the other half.
 *
 * `UNIQUE (office_id, deed_number)` needs no partial-index clause: both PostgreSQL
 * and SQLite treat NULLs as distinct in a unique index, so any number of unnumbered
 * deeds coexist and two deeds in one Office cannot share a number.
 *
 * ## `deed_date` and `deed_type_code` are nullable, for the same kind of reason
 *
 * **`deed_date`** is the date the deed was executed. A deed being drafted has not
 * been executed, so requiring it at creation would force somebody to type a date
 * that is not yet true.
 *
 * **`deed_type_code`** stays opaque and nullable — the D-116 ruling for
 * `document_type_code`, and the D-102 ruling for `matters.service_type_id`, which
 * observed that *"requiring the column would make Matter uncreatable for as long as
 * the catalogue is empty."* M6 seeds no deed type catalogue.
 *
 * ## The three omissions
 *
 * **`locked_by` is not added.** The canonical list carries `locked_at` and no
 * `locked_by`, and the asymmetry is not obviously an omission: the other three
 * timestamps pair with an actor because a person performs each act, while locking —
 * under every reading available — is a *consequence* of finalization rather than a
 * separate act somebody performs. Adding the column would assert that somebody locks
 * a deed, which is one of the correction-mechanism questions. *(Contrast M5.4, where
 * `created_by` **was** added to `tasks`: there the Data Scope `OWN` predicate
 * structurally required an owner and no existing column could serve. Nothing here
 * requires `locked_by`.)*
 *
 * **`deleted_at` is not added, and there is no soft delete.** Four canonical sources
 * agree: the ERD omits the column; section 33 says finalized legal records
 * *"should generally use states such as ARCHIVED, VOID, SUPERSEDED, CANCELLED rather
 * than destructive deletion"*; `CLAUDE.md` section 30 forbids user-facing hard delete
 * for finalized Deeds outright; and **no `notary.deeds.delete` capability exists** to
 * authorize one.
 *
 * **`created_by` is not added either.** Unlike Task, the `OWN` predicate has
 * somewhere else to go — a Deed's reach resolves through its parent Matter's
 * `created_by` and `pic_user_id`. See `NotaryDeedVisibility`.
 *
 * ## Office ownership is structural on every reference
 *
 * The Matter, all three Documents and all three users a Deed names must belong to the
 * Deed's own Office, each through a composite foreign key resolving via this table's
 * `office_id`. Every support key those need already exists — `matters` since M4.2,
 * `documents` since M5.1, `users` since M5.4 — so **M6 adds none**, the first
 * milestone since M2 for which that is true.
 *
 * **`RESTRICT` everywhere.** A Document a deed depends on cannot be deleted out from
 * under it, and neither can the Matter. `SET NULL` is not merely undesirable but
 * unavailable: nulling a composite key nulls *both* columns including `office_id`,
 * which is `NOT NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notary_deeds', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The security boundary and the OFFICE scope predicate. Indexed
            // explicitly — PostgreSQL does not index a referencing column just
            // because it carries a foreign key.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // Required: a deed is always the output of a Matter. The domain is not
            // stored again here — `matters.domain` is the one discriminator, and a
            // second copy is a second thing that can disagree. That a deed's Matter
            // is a NOTARY Matter is enforced by the surface that creates it.
            $table->ulid('matter_id');

            // See the class docblock. Nullable, unique per Office where present.
            $table->string('deed_number', 100)->nullable();
            $table->date('deed_date')->nullable();
            $table->string('deed_type_code', 50)->nullable();

            $table->string('title');

            // Stable machine codes, never translated labels (CLAUDE.md section 12).
            // CHECK-constrained below rather than a PostgreSQL native ENUM, per
            // section 13.
            $table->string('status', 20)->default(NotaryDeedStatus::DRAFT->value);

            // Three pointers, not one polymorphic column with a role: the ERD names
            // three, they mean three different things, and a deed may legitimately
            // hold all three at once. Each points at a Document, never at a version
            // — the Document carries its own current-version pointer (D-116), so the
            // version history behind it stays intact.
            $table->ulid('draft_document_id')->nullable();
            $table->ulid('final_document_id')->nullable();
            $table->ulid('minuta_document_id')->nullable();

            // Each act records when and by whom, as a pair. `locked_at` has no
            // partner — see the class docblock.
            $table->timestamp('reviewed_at')->nullable();
            $table->ulid('reviewed_by')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();

            $table->timestamp('finalized_at')->nullable();
            $table->ulid('finalized_by')->nullable();

            // Canonical column, written by nothing in M6. There is no
            // `notary.deeds.lock` capability and no documented rule describing who
            // locks a deed or when.
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            // Plain keys so a row cannot point at a nonexistent record...
            $table->foreign('matter_id', 'notary_deeds_matter_foreign')
                ->references('id')->on('matters')->restrictOnDelete();

            foreach ([
                'draft_document_id' => 'notary_deeds_draft_document_foreign',
                'final_document_id' => 'notary_deeds_final_document_foreign',
                'minuta_document_id' => 'notary_deeds_minuta_document_foreign',
            ] as $column => $name) {
                $table->foreign($column, $name)
                    ->references('id')->on('documents')->restrictOnDelete();
            }

            // ...and the composite keys that make Office agreement structural.
            // Every one resolves through this table's own `office_id`, so none of
            // them can disagree with another.
            $table->foreign(['matter_id', 'office_id'], 'notary_deeds_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();

            foreach ([
                'draft_document_id' => 'notary_deeds_draft_document_office_foreign',
                'final_document_id' => 'notary_deeds_final_document_office_foreign',
                'minuta_document_id' => 'notary_deeds_minuta_document_office_foreign',
            ] as $column => $name) {
                $table->foreign([$column, 'office_id'], $name)
                    ->references(['id', 'office_id'])->on('documents')->restrictOnDelete();
            }

            foreach ([
                'reviewed_by' => 'notary_deeds_reviewed_by_office_foreign',
                'approved_by' => 'notary_deeds_approved_by_office_foreign',
                'finalized_by' => 'notary_deeds_finalized_by_office_foreign',
            ] as $column => $name) {
                $table->foreign([$column, 'office_id'], $name)
                    ->references(['id', 'office_id'])->on('users')->restrictOnDelete();
            }

            // A legal number is unique within the office that issued it. NULLs are
            // distinct in a unique index on both connections, so unnumbered drafts
            // coexist freely.
            $table->unique(['office_id', 'deed_number'], 'notary_deeds_office_number_unique');

            // The questions a deed list actually asks: what is in flight in this
            // Office, what belongs to this Matter, and what was executed when.
            $table->index(['office_id', 'status'], 'notary_deeds_office_status_index');
            $table->index('matter_id', 'notary_deeds_matter_index');
            $table->index('deed_date', 'notary_deeds_deed_date_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $statuses = implode("', '", NotaryDeedStatus::values());

            // All six canonical values are storable. Only four are reachable
            // through the API — that is a surface rule, not a schema rule, because
            // the vocabulary is canonical even where the rule reaching it is not.
            $connection->statement(
                "ALTER TABLE notary_deeds ADD CONSTRAINT notary_deeds_status_check CHECK (status IN ('{$statuses}'))"
            );

            // Each act is recorded as a pair or not at all. Half of a review is a
            // row nobody can explain — the `tasks_completion_pair_check` reasoning
            // (D-119), applied three times.
            foreach (['reviewed', 'approved', 'finalized'] as $act) {
                $connection->statement(
                    "ALTER TABLE notary_deeds ADD CONSTRAINT notary_deeds_{$act}_pair_check "
                    ."CHECK (({$act}_at IS NULL AND {$act}_by IS NULL) "
                    ."OR ({$act}_at IS NOT NULL AND {$act}_by IS NOT NULL))"
                );
            }
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs there.
        // The enum cast and the model guards hold these on that connection.
    }

    public function down(): void
    {
        Schema::dropIfExists('notary_deeds');
    }
};
