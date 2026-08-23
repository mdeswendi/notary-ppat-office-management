<?php

use App\Domains\Matter\Enums\MatterStageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Matter's running workflow, its stages, and its transition history
 * (M4.7, D-104, D-112).
 *
 * M4.6 built the configuration — templates and their stages. This builds the
 * **running** side: what a particular Matter is actually doing. **No permission
 * is added; the count stays at 177.** `notary.matters.change_stage` and
 * `ppat.matters.change_stage` have been canonical since the catalogue was
 * transcribed and have carried a deferred badge since M4.4; M4.7 gives them a
 * route and removes the badge.
 *
 * **Still no workflow content** (D-104). Nothing seeds a template, so on a fresh
 * deployment no Matter gets a workflow at all — and that is the ordinary path
 * rather than an edge case. The engine waits for configuration an office enters
 * once a qualified domain source completes the two workflow documents.
 *
 * ## Snapshotting, which is the point rather than decoration
 *
 * `CLAUDE.md` section 18 requires that editing a template must not retroactively
 * change a Matter already running. Three mechanisms together guarantee it:
 *
 *   1. `matter_workflows.workflow_version` records the iteration instantiated
 *      from, since M4.6 made `version` a counter on one row (D-111);
 *   2. every stage instance copies `stage_code`, both names, and `sequence_no`
 *      at instantiation, so a renamed or renumbered template stage changes
 *      nothing that is already running;
 *   3. **`matter_stage_instances.workflow_stage_id` is `RESTRICT`, never
 *      `CASCADE`.** M4.6's stage table cascades from its template, so a
 *      `CASCADE` here would chain: deleting a template would delete its stages,
 *      which would delete the instances of Matters that ran it, silently
 *      destroying the history the other two mechanisms exist to preserve. This
 *      migration is where that chain is cut, and the whole snapshot design rests
 *      on it.
 *
 * The copied names carry a trap worth naming explicitly. **`stage_name_snapshot_id`
 * is not a foreign key.** The `_id` is the ISO 639-1 code for Bahasa Indonesia,
 * matching `name_id` / `name_en` everywhere else in this schema — it holds a
 * human-readable stage name, not a ULID. Every other `*_id` column here does hold
 * a reference, so the name is genuinely misleading; it is transcribed from
 * `03_DATABASE_ERD.md` section 11 rather than renamed, and a test asserts it
 * holds a name.
 *
 * ## History is append-only
 *
 * `matter_stage_history` has no `updated_at`, no `deleted_at`, and no update path
 * in the application. D-104 records that whether a stage transition carries legal
 * state is undecided, and treats the table as append-only from the outset — the
 * safe direction to be wrong in. `CLAUDE.md` section 31 applies the same rule to
 * audit records generally.
 *
 * `reason` is free text and is therefore a leak surface: D-105 is explicit that
 * Party identity must never be persisted in a field like this. The model states
 * it; no automated check can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_workflows', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // One Matter, one workflow. The uniqueness is the rule: a second
            // instantiation would leave two answers to "what is this Matter
            // doing", and nothing could choose between them.
            //
            // The consequence is stated rather than discovered: a Matter created
            // before an office configured any template can never acquire one
            // later, because no re-instantiation path exists in M4.7. That needs
            // its own decision, not a quiet upsert here.
            $table->ulid('matter_id');
            $table->unique('matter_id', 'matter_workflows_matter_id_unique');

            $table->foreign('matter_id', 'matter_workflows_matter_foreign')
                ->references('id')->on('matters')->restrictOnDelete();

            // RESTRICT: a template that Matters are running is not something to
            // delete out from under them. Retirement is `is_active` (D-111).
            $table->foreignUlid('workflow_template_id')
                ->index()
                ->constrained('workflow_templates')
                ->restrictOnDelete();

            // Which iteration of that template this Matter was started from.
            // Meaningful precisely because M4.6 made `version` a counter on a
            // single row rather than a row per version (D-111): the foreign key
            // says which template, this says which iteration of it, and the
            // stage snapshots below carry the content of that iteration.
            $table->unsignedInteger('workflow_version');

            $table->timestamp('started_at')->nullable();

            // Stamped when the Matter itself is completed (D-112). A stage
            // becomes COMPLETED by moving on from it, so the final stage would
            // never complete on its own and this column would be unreachable.
            // Completing the Matter is the act an office already performs and is
            // already authorized for, so it carries the workflow with it.
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });

        Schema::create('matter_stage_instances', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // CASCADE: a stage instance has no existence apart from the workflow
            // run it belongs to.
            $table->foreignUlid('matter_workflow_id')
                ->index()
                ->constrained('matter_workflows')
                ->cascadeOnDelete();

            // **RESTRICT, and this is the load-bearing line of the migration.**
            // See the class docblock: M4.6's stages cascade from their template,
            // so CASCADE here would chain a template deletion all the way into
            // running Matters' history.
            $table->ulid('workflow_stage_id');
            $table->index('workflow_stage_id', 'matter_stage_instances_stage_index');

            $table->foreign('workflow_stage_id', 'matter_stage_instances_stage_foreign')
                ->references('id')->on('workflow_stages')->restrictOnDelete();

            // The snapshot. Copied at instantiation and never refreshed: editing
            // the template afterwards changes none of it (CLAUDE.md section 18).
            $table->string('stage_code', 50);

            // **Not foreign keys.** `_id` and `_en` are locale codes here, exactly
            // as in `name_id` / `name_en` — these hold displayable stage names.
            $table->string('stage_name_snapshot_id');
            $table->string('stage_name_snapshot_en');

            $table->unsignedInteger('sequence_no');

            // Stable machine codes, never translated labels (CLAUDE.md section
            // 12). CHECK-constrained below rather than a PostgreSQL native ENUM,
            // per section 13.
            $table->string('status', 20);

            $table->index('status', 'matter_stage_instances_status_index');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Who is working this stage. **This is operational information and
            // never an authorization predicate** (D-100): a stage assignee does
            // not thereby gain Matter `ASSIGNED` reach, which stays
            // `matters.pic_user_id` and nothing else. Nothing in M4.7 writes this
            // column — no stage-assignment surface exists — and a test asserts
            // the scope predicate ignores it.
            //
            // `nullOnDelete` rather than RESTRICT: a departed colleague should
            // not pin a workflow row, and losing the assignee loses nothing the
            // history does not already record.
            $table->foreignUlid('assigned_user_id')
                ->nullable()
                ->index()
                ->constrained('users')
                ->nullOnDelete();

            // Approval, recorded but not yet performed. `workflow_stages` carries
            // `requires_approval` and `approval_permission` from M4.6; **M4.7
            // ships no approval endpoint**, so both of these stay null. Building
            // the columns without the act is deliberate — the ERD names them, and
            // the milestone that approves needs somewhere to write.
            $table->timestamp('approved_at')->nullable();

            $table->foreignUlid('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // One stage per position and one per code, within this run. Both
            // mirror the template's own constraints (D-111) rather than adding a
            // rule: a snapshot of a valid template is itself valid.
            $table->unique(['matter_workflow_id', 'sequence_no'], 'matter_stage_instances_workflow_sequence_unique');
            $table->unique(['matter_workflow_id', 'stage_code'], 'matter_stage_instances_workflow_code_unique');
        });

        Schema::create('matter_stage_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Keyed on the **Matter**, not the workflow, following the canonical
            // field list. History outlives any particular run: if a
            // re-instantiation path is ever built, the record of what happened
            // before must not vanish with the old workflow row.
            $table->ulid('matter_id');
            $table->index('matter_id', 'matter_stage_history_matter_index');

            $table->foreign('matter_id', 'matter_stage_history_matter_foreign')
                ->references('id')->on('matters')->restrictOnDelete();

            // Codes rather than foreign keys, deliberately. History records what
            // was said at the time; resolving it through a live stage row would
            // let a later template edit rewrite the past — the exact failure
            // snapshotting exists to prevent.
            $table->string('from_stage_code', 50)->nullable();
            $table->string('to_stage_code', 50);

            $table->foreignUlid('changed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // Free text, and a leak surface: Party identity must never be written
            // here (D-105). No automated check can enforce that; the model says
            // it and the interface warns.
            $table->text('reason')->nullable();

            // **`changed_at` and nothing else.** No `updated_at`, because a
            // history row is never updated, and no `deleted_at`, because it is
            // never deleted. Append-only from the outset (D-104), which is the
            // safe direction to be wrong in if a transition ever turns out to
            // carry legal state.
            $table->timestamp('changed_at');
            $table->index('changed_at', 'matter_stage_history_changed_at_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $statuses = implode("', '", MatterStageStatus::values());

            $connection->statement(
                "ALTER TABLE matter_stage_instances ADD CONSTRAINT matter_stage_instances_status_check CHECK (status IN ('{$statuses}'))"
            );

            // Laravel's `unsigned*` types are MySQL concepts; PostgreSQL maps
            // them to signed columns without complaint. The M4.1 lesson, applied
            // again.
            $connection->statement(
                'ALTER TABLE matter_stage_instances ADD CONSTRAINT matter_stage_instances_sequence_no_check '
                .'CHECK (sequence_no >= 1)'
            );

            $connection->statement(
                'ALTER TABLE matter_workflows ADD CONSTRAINT matter_workflows_workflow_version_check '
                .'CHECK (workflow_version >= 1)'
            );
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs
        // there. The enum cast on the model is what refuses an invalid status on
        // that connection.
    }

    public function down(): void
    {
        // Dependency order, rather than relying on the cascade.
        Schema::dropIfExists('matter_stage_history');
        Schema::dropIfExists('matter_stage_instances');
        Schema::dropIfExists('matter_workflows');
    }
};
