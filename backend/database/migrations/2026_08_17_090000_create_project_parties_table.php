<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Parties take part in a Project (M3.4, D-092, D-098).
 *
 * **Current working state, not a historical ledger.** This is the sharpest
 * difference from `company_people`, and it is deliberate rather than an
 * oversight: that table carries `effective_from` / `effective_until` because
 * "who was the director in March" must stay answerable for deeds executed in
 * March (D-083). Project participation answers "who is involved in this work
 * now". `03_DATABASE_ERD.md` section 7 gives it no period columns, no
 * `updated_at`, and no `deleted_at`, and none is added here — importing history
 * semantics by analogy would build a mechanism the canonical shape declines and
 * then oblige every reader to maintain it.
 *
 * So the row has `created_at` and `created_by` and nothing further. Removing a
 * participation deletes the relationship row outright; it never touches the
 * Project or the Party.
 *
 * **The same-office invariant is enforced by the database**, exactly as M2.4 did
 * for `company_people` (D-080). `office_id` here is a *constraint carrier*, not
 * independent data: two composite foreign keys reference `projects (id,
 * office_id)` and `parties (id, office_id)` through the **same** column, so both
 * endpoints must agree with it and therefore with each other. A cross-office
 * participation is unrepresentable rather than merely discouraged — which
 * matters because `ALL` grants visibility and administrative reach, never
 * permission to redefine domain ownership.
 *
 * `parties` already carried `parties_id_office_id_unique` from M2.1. `projects`
 * did not, so this migration adds the matching support key; a composite foreign
 * key needs a unique index on the referenced pair, and without it the invariant
 * could only be validated rather than enforced.
 *
 * **No participant cardinality is invented** (D-092). No `UNIQUE (project_id,
 * party_id)` — that would assert one Party holds exactly one Project role. No
 * `UNIQUE (project_id, party_id, role_code)` — that would assert the triple is
 * the identity, and it would additionally be meaningless while `role_code` is
 * nullable. Neither rule appears in any canonical document, and a unique index
 * is a business rule wearing an index's clothing.
 *
 * **No Party identity is copied.** No NIK, NPWP, `tax_id`, mask, fingerprint,
 * name, or contact detail. The row points at a Party by id; identity is read, if
 * ever, through the Party surfaces that already authorize it (D-082).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The support key the composite foreign key below requires. Redundant
        // with the primary key for uniqueness purposes — `id` is already unique,
        // so this adds no constraint the table did not have — but PostgreSQL
        // needs a unique index on the exact referenced column pair. `parties`
        // has carried the equivalent since M2.1.
        Schema::table('projects', function (Blueprint $table): void {
            $table->unique(['id', 'office_id'], 'projects_id_office_id_unique');
        });

        Schema::create('project_parties', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->ulid('project_id');
            $table->ulid('party_id');

            // Constraint carrier. Never independent data and never request
            // input: it is written from the Project and then checked against
            // both endpoints by the composite keys below.
            $table->ulid('office_id');

            // Plain foreign keys so a participation cannot point at a
            // nonexistent record, and RESTRICT so neither endpoint can be
            // removed out from under a live participation — matching the
            // ownership architecture the Project and Party tables already use.
            $table->foreign('project_id', 'project_parties_project_foreign')
                ->references('id')->on('projects')->restrictOnDelete();

            $table->foreign('party_id', 'project_parties_party_foreign')
                ->references('id')->on('parties')->restrictOnDelete();

            // The invariant itself. Both endpoints resolve through the same
            // `office_id` column, so they cannot disagree with each other.
            $table->foreign(['project_id', 'office_id'], 'project_parties_project_office_foreign')
                ->references(['id', 'office_id'])->on('projects')->restrictOnDelete();

            $table->foreign(['party_id', 'office_id'], 'project_parties_party_office_foreign')
                ->references(['id', 'office_id'])->on('parties')->restrictOnDelete();

            // Opaque relationship classification, nullable, and deliberately
            // **not** an enum or a CHECK. No canonical participant-role
            // vocabulary exists — `03_DATABASE_ERD.md` offers six codes and
            // labels them examples, not a catalogue — so constraining the column
            // would invent the legal role list M3 has no authority to write
            // (CLAUDE.md section 62, D-092).
            $table->string('role_code', 30)->nullable();

            // Designation only. No cardinality rule: not at-least-one, not
            // exactly-one, not one-per-role. Several rows may be primary at once,
            // which is deliberate pending domain authority (D-092).
            $table->boolean('is_primary')->default(false);

            $table->text('notes')->nullable();

            // Attribution survives the person who typed it (D-050). No
            // `updated_by` counterpart: see the note above on why this table
            // carries no `updated_at` either.
            $table->foreignUlid('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('created_at')->nullable();

            // Ordinary lookup indexes, no uniqueness. The first serves the only
            // list this table has; the second answers "which Projects involve
            // this Party", which the Party side will eventually ask.
            $table->index('project_id', 'project_parties_project_index');
            $table->index('party_id', 'project_parties_party_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_parties');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique('projects_id_office_id_unique');
        });
    }
};
