<?php

use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Domains\Project\Enums\ProjectPriority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matter — the operational unit of work inside a Project (M4.2, D-107).
 *
 * Fields follow `03_DATABASE_ERD.md` section 9. ULID primary key per D-023.
 * **One root table with a `domain` discriminator** (D-102): no `notary_matters`,
 * no `ppat_matters`, and no field standing in for one — those belong to M6 and M7
 * with their domain content.
 *
 * Two invariants are **structural rather than validated**, because a rule the
 * database cannot express is a rule somebody eventually routes around:
 *
 *   Matter Office == Project Office              (project_id, office_id)
 *   Matter Office == Service Type Office
 *   Matter domain == Service Type domain         (service_type_id, office_id, domain)
 *
 * The second pair is one composite key doing two jobs, and it is why this
 * migration also adds `UNIQUE (id, office_id, domain)` to `service_types`: a
 * composite foreign key needs a unique index on the exact referenced columns, and
 * M4.1 shipped only `(id, office_id)`. That is an additive `ALTER` on a table
 * holding zero rows in every environment.
 *
 * Deliberately absent, each deferred to the milestone that owns it:
 *
 *   matter_number       M4.3, together with its allocator. D-095 exactly: the
 *                       column and the allocator arrive together, so no milestone
 *                       inherits a backfill and a uniqueness question it has not
 *                       answered
 *   current_stage_id    M4.7, together with the real `matter_stage_instances`
 *                       foreign key. A nullable ULID now would be a pointer at a
 *                       table that does not exist, validated by nothing
 *   notary_matters      M6 / M7 (D-102)
 *   ppat_matters
 *
 * **`deleted_at` is reserved schema capability with no lifecycle reaching it**
 * (D-102). The column exists because the ERD carries it; the model deliberately
 * does **not** use `SoftDeletes`, so no global scope filters any query and
 * nothing can set it. M4 ships no Matter archive or restore, and the canonical
 * registry defines no code that could authorize one.
 *
 * **`status` carries no database default.** The database records what the
 * application decided; it does not decide an initial state. Choosing one here
 * would be the thin end of the transition matrix D-102 refuses.
 *
 * No Party identity of any kind is copied here (D-082): a Matter references a
 * Project and optionally a Service Type, and reads identity, if ever, through the
 * surfaces that already authorize it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The support key the Service Type composite foreign key below requires.
        // M4.1 added `(id, office_id)` for the Office half; carrying the domain
        // too is what makes "a Notary Matter cannot use a PPAT service"
        // unrepresentable rather than merely validated.
        Schema::table('service_types', function (Blueprint $table): void {
            $table->unique(['id', 'office_id', 'domain'], 'service_types_id_office_id_domain_unique');
        });

        Schema::create('matters', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->ulid('project_id');

            // Office ownership is the security boundary and the OFFICE scope
            // predicate (D-100). Required, inherited from the parent Project at
            // creation (D-099), and immutable during M4 — the immutability guard
            // lives in the model, because it is an update rule and a column
            // cannot express one.
            $table->ulid('office_id');

            $table->ulid('service_type_id')->nullable();

            // Stable machine codes, never translated labels. Required and
            // immutable: `domain` decides which capability namespace authorizes
            // the record at all (D-101), so flipping it would reclassify work
            // already done.
            $table->string('domain', 20);

            // Plain foreign keys so a Matter cannot point at a nonexistent
            // record, and RESTRICT so neither endpoint can be removed out from
            // under live work — matching `project_parties` and the ownership
            // architecture the Project and Party tables already use.
            //
            // Project soft deletion leaves the row, so this constraint is
            // unaffected by archiving: an existing Matter survives its Project
            // being archived, and whether a *new* Matter may be opened under an
            // archived Project is an authorization question answered by
            // ProjectVisibility, not by this key.
            $table->foreign('project_id', 'matters_project_foreign')
                ->references('id')->on('projects')->restrictOnDelete();

            $table->foreign('office_id', 'matters_office_foreign')
                ->references('id')->on('offices')->restrictOnDelete();

            $table->foreign('service_type_id', 'matters_service_type_foreign')
                ->references('id')->on('service_types')->restrictOnDelete();

            // The Project invariant. Both endpoints resolve through the same
            // `office_id` column, so a Matter cannot disagree with its Project
            // about which Office owns the work. `projects` has carried the
            // matching support key since M3.4.
            $table->foreign(['project_id', 'office_id'], 'matters_project_office_foreign')
                ->references(['id', 'office_id'])->on('projects')->restrictOnDelete();

            // The Service Type invariant, doing two jobs at once: same Office and
            // same domain. PostgreSQL treats a composite foreign key with any
            // NULL component as satisfied, so a Matter with no Service Type stays
            // valid — which is what D-102's nullable ruling requires.
            //
            // Never `SET NULL`: erasing a Matter's classification because a
            // catalogue was tidied would lose data a historical record depends on
            // (CLAUDE.md section 63). Nothing deletes a Service Type anyway —
            // retirement is `is_active` (D-106) — which makes this RESTRICT
            // nearly unreachable and correct regardless.
            $table->foreign(
                ['service_type_id', 'office_id', 'domain'],
                'matters_service_type_office_domain_foreign'
            )->references(['id', 'office_id', 'domain'])->on('service_types')->restrictOnDelete();

            $table->string('title');

            // Stable codes, never translated labels (CLAUDE.md section 12).
            $table->string('status', 20);
            $table->string('priority', 20)->nullable();

            // The ASSIGNED predicate (D-100). Nullable because a Matter may be
            // unassigned; a null never matches an actor id, so an unassigned
            // Matter is simply unreachable by an ASSIGNED-only grant — the
            // correct fail-closed behaviour rather than a special case.
            //
            // Indexed because it is a scope predicate, not merely a foreign key.
            //
            // **Same-Office PIC is a locked rule enforced at M4.4**, where the
            // assignment surface lives (D-097's reasoning: a cross-office
            // assignment would hand somebody reach their scope never included).
            // No `(pic_user_id, office_id)` composite key is added here — `users`
            // carries no matching support key, and building one for an invariant
            // another milestone owns would be construction ahead of requirement.
            $table->foreignUlid('pic_user_id')
                ->nullable()
                ->index()
                ->constrained('users')
                ->restrictOnDelete();

            $table->date('opened_at')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->timestamp('completed_at')->nullable();

            // The canonical descriptive field for a Matter. The ERD gives Matter
            // `notes` where it gives Project `description`; no `description`
            // column is invented here.
            $table->text('notes')->nullable();

            // Attribution must survive the person who typed it (D-050). Indexed
            // for the same reason as `pic_user_id`: `created_by` is the OWN
            // predicate, so it is queried, not merely stored. A null never
            // matches an actor id, so an unattributed Matter fails closed for an
            // OWN-only grant.
            $table->foreignUlid('created_by')
                ->nullable()
                ->index()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignUlid('updated_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            // Reserved schema capability only — see the class note. No
            // `SoftDeletes`, no global scope, no archive or restore surface.
            $table->softDeletes();

            // The support key M4.5's `matter_parties` will reference, so a
            // cross-office Matter participation becomes unrepresentable the way
            // `project_parties` is (D-105). Added now rather than by a later
            // ALTER, exactly as M4.1 did for `service_types`.
            $table->unique(['id', 'office_id'], 'matters_id_office_id_unique');

            // A Project's Matters are the list this table exists to serve, and it
            // is usually narrowed by domain because the two surfaces are split.
            $table->index(['project_id', 'domain'], 'matters_project_domain_index');
            $table->index(['office_id', 'domain', 'status'], 'matters_office_domain_status_index');
            $table->index('service_type_id', 'matters_service_type_index');
        });

        // Only canonical codes are storable. A CHECK rather than a PostgreSQL
        // native ENUM, per CLAUDE.md section 13 — the enum lives in PHP, and the
        // database refuses anything the enum does not name.
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $domains = implode("', '", MatterDomain::values());
            $statuses = implode("', '", MatterStatus::values());
            $priorities = implode("', '", ProjectPriority::values());

            $connection->statement(
                "ALTER TABLE matters ADD CONSTRAINT matters_domain_check CHECK (domain IN ('{$domains}'))"
            );

            $connection->statement(
                "ALTER TABLE matters ADD CONSTRAINT matters_status_check CHECK (status IN ('{$statuses}'))"
            );

            // Nullable, so the constraint must permit NULL explicitly — the same
            // shape `projects_priority_check` uses.
            $connection->statement(
                'ALTER TABLE matters ADD CONSTRAINT matters_priority_check '
                ."CHECK (priority IS NULL OR priority IN ('{$priorities}'))"
            );
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs
        // there. The enum casts on the model are what refuse an invalid value —
        // stated plainly rather than left to be discovered, exactly as the
        // `projects`, `parties`, and `service_types` migrations say of their own
        // coded columns.
    }

    public function down(): void
    {
        Schema::dropIfExists('matters');

        Schema::table('service_types', function (Blueprint $table): void {
            $table->dropUnique('service_types_id_office_id_domain_unique');
        });
    }
};
