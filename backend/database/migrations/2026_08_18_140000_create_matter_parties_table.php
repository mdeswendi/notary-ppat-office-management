<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Parties take part in a Matter (M4.5, D-105, D-110).
 *
 * **Independent of `project_parties`: not inherited, not copied, not
 * synchronized** (D-105). A Matter's participants are not derived from its
 * parent Project's, and nothing here reads that table. Project participants may
 * later serve as *candidate context* for whoever is typing — a convenience, not
 * a data relationship. Two tables that silently mirror each other drift apart,
 * and the drift is found by somebody reading the wrong one.
 *
 * **The same-Office invariant is structural**, exactly as M2.4 did for
 * `company_people` (D-080) and M3.4 for `project_parties` (D-098). `office_id`
 * here is a *constraint carrier*, not independent data: two composite foreign
 * keys reference `matters (id, office_id)` and `parties (id, office_id)` through
 * the **same** column, so both endpoints must agree with it and therefore with
 * each other. A cross-office Matter participation is unrepresentable rather than
 * merely discouraged — **including for an actor holding `ALL`**, because `ALL`
 * grants reach and administrative visibility, never permission to redefine
 * domain ownership.
 *
 * Both support keys already exist: `parties_id_office_id_unique` since M2.1, and
 * `matters_id_office_id_unique` added by M4.2 for precisely this table (D-107).
 * Unlike M3.4, which had to add the `projects` one, **this migration adds no
 * support key** and therefore drops none on the way back down.
 *
 * **No participant cardinality is invented** (D-105). No `UNIQUE (matter_id,
 * party_id)` — that would assert one Party holds at most one role in a Matter,
 * and an Indonesian notarial or PPAT matter may legitimately need the same
 * person as `SELLER` in their own right and as `AUTHORIZED_PERSON` for someone
 * else. Whether that is permitted is a domain question with no canonical answer
 * here, and a unique index is a business rule wearing an index's clothing. No
 * `UNIQUE (matter_id, party_id, role_code)` either: it would assert the triple
 * is the identity and would additionally be meaningless while `role_code` is
 * nullable.
 *
 * **Current working state, not a historical ledger.** No `deleted_at`, no
 * `effective_from`, no `effective_until`. `company_people` keeps history because
 * deeds executed in March depend on who was a director in March (D-083); nothing
 * yet depends on a Matter's participant list as it stood last week. Removing a
 * participation deletes the relationship row outright; it never touches the
 * Matter and never touches the Party. Correction semantics, if ever needed, must
 * be designed explicitly in the milestone that needs them rather than implied by
 * a `deleted_at` added quietly here.
 *
 * **`updated_at` is present here and absent on `project_parties`**, and the
 * difference is transcribed rather than reasoned: `03_DATABASE_ERD.md` section 9
 * lists `updated_at` for `matter_parties` and section 7 gives `project_parties`
 * none. There is no `updated_by` counterpart in either, so the column records
 * *when* a correction happened and not *who* made it — which is what the
 * canonical field list asks for, and inventing the missing half would be
 * building the ledger the table declines to be.
 *
 * **Deferred, each for a stated reason** (D-105): `represented_by_party_id`
 * (DOMAIN VALIDATION REQUIRED — representation, proxy and legal capacity are
 * different things with different deed consequences) and `sequence_no`
 * (semantics unvalidated — display order, signing order, legal priority and
 * appearance order are four different things the column name distinguishes
 * between not at all). Neither exists as a nullable placeholder.
 *
 * **No Party identity is copied.** No NIK, NPWP, `tax_id`, mask, fingerprint,
 * name, or contact detail. The row points at a Party by id; identity is read, if
 * ever, through the Party surfaces that already authorize it (D-082).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_parties', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->ulid('matter_id');
            $table->ulid('party_id');

            // Constraint carrier. Never independent data and never request
            // input: it is written from the Matter and then checked against both
            // endpoints by the composite keys below.
            $table->ulid('office_id');

            // Plain foreign keys so a participation cannot point at a
            // nonexistent record, and RESTRICT so neither endpoint can be
            // removed out from under a live participation.
            $table->foreign('matter_id', 'matter_parties_matter_foreign')
                ->references('id')->on('matters')->restrictOnDelete();

            $table->foreign('party_id', 'matter_parties_party_foreign')
                ->references('id')->on('parties')->restrictOnDelete();

            // The invariant itself. Both endpoints resolve through the same
            // `office_id` column, so they cannot disagree with each other.
            $table->foreign(['matter_id', 'office_id'], 'matter_parties_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();

            $table->foreign(['party_id', 'office_id'], 'matter_parties_party_office_foreign')
                ->references(['id', 'office_id'])->on('parties')->restrictOnDelete();

            // Opaque relationship classification, nullable, and deliberately
            // **not** an enum or a CHECK. `03_DATABASE_ERD.md` section 9 offers
            // SELLER, BUYER, SELLER_SPOUSE, DIRECTOR, COMMISSIONER and WITNESS
            // and labels them *example* role codes; constraining the column
            // would turn examples into the catalogue the document says they are
            // not (D-105, CLAUDE.md section 62).
            //
            // 30 characters, matching `project_parties`. No canonical length
            // exists, and two participation tables disagreeing about how long a
            // role code may be would be an arbitrary difference to explain
            // later.
            $table->string('role_code', 30)->nullable();

            $table->text('notes')->nullable();

            // Attribution survives the person who typed it (D-050). No
            // `updated_by` counterpart, following the canonical field list.
            $table->foreignUlid('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            // Ordinary lookup indexes, no uniqueness. The first serves the only
            // list this table has; the second answers "which Matters involve
            // this Party", which the Party side will eventually ask; the third
            // serves Office-bounded reporting without implying a constraint.
            $table->index('matter_id', 'matter_parties_matter_index');
            $table->index('party_id', 'matter_parties_party_index');
            $table->index('office_id', 'matter_parties_office_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_parties');
    }
};
