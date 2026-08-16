<?php

use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Project\Enums\ProjectStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project — the M3 aggregate root for one client engagement (D-087).
 *
 * Office-owned, soft-deleted, and deliberately smaller than
 * `03_DATABASE_ERD.md` section 7 proposes. Two of that section's columns are
 * **absent on purpose**, and both absences are recorded rather than left to look
 * like oversights:
 *
 * `primary_client_party_id`  Rejected by D-092. `project_parties` — which M3.4
 *                            owns — already carries participation and has an
 *                            `is_primary` flag, so the column is a second
 *                            mechanism for one fact. It also re-creates the
 *                            "client" concept D-078 refused, one column at a
 *                            time. No `clients` table, no `client_id`, and no
 *                            Party identity copied here.
 *
 * `project_number`           Deferred to M3.2, which owns internal-reference
 *                            allocation (D-094). The column is withheld rather
 *                            than added nullable-and-empty, following exactly
 *                            the precedent D-086 set: M2.1 refused to add a
 *                            fingerprint column before its construction was
 *                            settled, because "a column added on speculation is
 *                            one somebody fills in wrongly". Adding it here
 *                            would leave every M3.1 Project with a null
 *                            reference and hand M3.2 a backfill plus a
 *                            uniqueness question it has not answered yet. The
 *                            column and its allocator arrive together.
 *
 * Also absent, and not deferred so much as never applicable here: any Matter
 * foreign key. Matter does not exist until M4, and Project does not point at it
 * — Matter will reference Project, not the reverse (D-087).
 *
 * **`status` carries no default.** The database records what the application
 * decided; it does not decide an initial state. Choosing one here would be the
 * thin end of the transition matrix D-091 refuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Office ownership is the security boundary and the OFFICE scope
            // predicate (D-088, D-089). Required, and immutable during M3 — no
            // transfer operation exists, and the guard for that lives in the
            // model rather than here, because "immutable" is an update rule and
            // a column cannot express one.
            //
            // `restrictOnDelete` matches `parties.office_id` and
            // `users.office_id`: removing an Office must never silently take its
            // work with it. Indexed explicitly — PostgreSQL does not index a
            // referencing column just because it carries a foreign key.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Stable codes, never translated labels (CLAUDE.md section 12).
            $table->string('status', 20);
            $table->string('priority', 20)->nullable();

            // The ASSIGNED predicate (D-088). Nullable because a Project may be
            // unassigned; a null never matches an actor id, so an unassigned
            // Project is simply unreachable by an ASSIGNED-only grant, which is
            // the correct fail-closed behaviour rather than a special case.
            //
            // Indexed because it is a scope predicate, not merely a foreign key.
            $table->foreignUlid('pic_user_id')
                ->nullable()
                ->index()
                ->constrained('users')
                ->restrictOnDelete();

            $table->date('opened_at')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Attribution must survive the person who typed it (D-050). Indexed
            // for the same reason as `pic_user_id`: `created_by` is the OWN
            // predicate, so it is queried, not merely stored.
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

            // Persistence-level archive, distinct from business status ARCHIVED
            // (D-093). `projects.restore` reverses this and nothing else.
            $table->softDeletes();

            // The list filters by Office first and usually narrows by status.
            $table->index(['office_id', 'status'], 'projects_office_id_status_index');
            $table->index('title', 'projects_title_index');
        });

        // Only canonical codes are storable. A CHECK rather than a PostgreSQL
        // native ENUM, per CLAUDE.md section 13 — the enum lives in PHP, and the
        // database refuses anything the enum does not name.
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $statuses = implode("', '", ProjectStatus::values());
            $priorities = implode("', '", ProjectPriority::values());

            $connection->statement(
                "ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('{$statuses}'))"
            );

            // Nullable, so the constraint must permit NULL explicitly.
            $connection->statement(
                'ALTER TABLE projects ADD CONSTRAINT projects_priority_check '
                ."CHECK (priority IS NULL OR priority IN ('{$priorities}'))"
            );
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs
        // there. The enum casts on the model are what refuse an invalid value —
        // stated plainly rather than left to be discovered, exactly as the
        // `parties` migration says of `party_type`.
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
