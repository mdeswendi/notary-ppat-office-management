<?php

use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Task\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational work: who is doing what, and by when (M5.4, D-119).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 15, with **one addition and one
 * omission**, both decided rather than drifted into.
 *
 * ## `created_by` is added, and that is the question M5.0 handed this milestone
 *
 * The canonical field list carries `assigned_by` and **no `created_by`**. The M5
 * lock section 11.1 recorded that as a transcription question M5.4 must resolve
 * explicitly, because the Data Scope `OWN` predicate needs an owner.
 *
 * **`assigned_by` cannot be that owner.** It records who last handed the work
 * over, so it changes every time a task is reassigned — ownership would move
 * between people without anybody deciding it — and a task nobody has assigned yet
 * would have no owner at all. So the column is added, and the extension to the
 * canonical list is recorded rather than quietly made.
 *
 * `OWN` is `created_by`; `ASSIGNED` is `assigned_to`. **Two predicates, kept
 * separate**, exactly as the lock's section 11.2 has them — they union when an
 * actor holds both (D-028), which is how "tasks I raised or was given" is
 * expressed without one predicate swallowing the other.
 *
 * ## `workflow_stage_instance_id` is omitted
 *
 * The ERD lists it: a Task produced by a workflow stage. `matter_stage_instances`
 * exists as of M4.7, so unlike the blocked document junctions this column *could*
 * be written — and it is left out anyway, because **nothing would set it.**
 * `task_templates` is what connects a stage to the tasks it raises, and D-104 plus
 * the lock's section 11.3 keep that unbuilt: which stage produces which task, for
 * whom, and by when is workflow content nobody has authored. A nullable pointer
 * no code can fill is the placeholder D-095 refused.
 *
 * ## Office ownership is structural on all four user columns
 *
 * Every user a Task names must work in the Task's own Office, and each is a
 * composite foreign key through the shared `office_id` carrier — the construction
 * `company_people` (D-080), `project_parties` (D-098), `matters` (D-107) and the
 * document junctions (D-116) all use. The `UNIQUE (id, office_id)` those keys need
 * on `users` is added by the migration before this one.
 *
 * **`RESTRICT` everywhere, and `SET NULL` nowhere.** Setting a composite key to
 * null would null *both* its columns, including `office_id`, which is `NOT NULL` —
 * the reason the obvious `nullOnDelete()` on `(project_id, office_id)` is not
 * merely stylistically wrong but would fail at runtime. Removing a Project or
 * Matter that still has tasks is refused instead, which is also the right answer:
 * work does not become ownerless because the engagement it belongs to was deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The security boundary and the OFFICE scope predicate. Indexed
            // explicitly — PostgreSQL does not index a referencing column just
            // because it carries a foreign key.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // **Both optional, and a Task with neither is complete rather than a
            // draft.** Office work is not always about a specific engagement:
            // renewing a licence, filing a return, chasing a signature. Requiring
            // a parent would have made the standalone case unrepresentable.
            $table->ulid('project_id')->nullable();
            $table->ulid('matter_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();

            // Stable machine codes, never translated labels (CLAUDE.md section
            // 12). CHECK-constrained below rather than a PostgreSQL native ENUM,
            // per section 13.
            $table->string('status', 30)->default(TaskStatus::OPEN->value);
            $table->string('priority', 20)->default(ProjectPriority::NORMAL->value);

            // **Nullable, because a Task can exist before anybody is given it.**
            // The M5.0 plan proposed defaulting it to the creator; that would make
            // every unassigned task look assigned, and `ASSIGNED` would then
            // silently mean "created by me" for exactly the tasks nobody has
            // picked up.
            $table->ulid('assigned_to')->nullable();
            $table->ulid('assigned_by')->nullable();

            // The OWN predicate. See the class docblock for why this is not
            // `assigned_by`.
            $table->ulid('created_by');

            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->ulid('completed_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Plain keys so a row cannot point at a nonexistent record...
            $table->foreign('project_id', 'tasks_project_foreign')
                ->references('id')->on('projects')->restrictOnDelete();

            $table->foreign('matter_id', 'tasks_matter_foreign')
                ->references('id')->on('matters')->restrictOnDelete();

            // ...and the composite keys that make the Office agreement
            // structural. Every one resolves through this table's own
            // `office_id`, so none of them can disagree with another.
            $table->foreign(['project_id', 'office_id'], 'tasks_project_office_foreign')
                ->references(['id', 'office_id'])->on('projects')->restrictOnDelete();

            $table->foreign(['matter_id', 'office_id'], 'tasks_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();

            foreach ([
                'assigned_to' => 'tasks_assigned_to_office_foreign',
                'assigned_by' => 'tasks_assigned_by_office_foreign',
                'created_by' => 'tasks_created_by_office_foreign',
                'completed_by' => 'tasks_completed_by_office_foreign',
            ] as $column => $name) {
                $table->foreign([$column, 'office_id'], $name)
                    ->references(['id', 'office_id'])->on('users')->restrictOnDelete();
            }

            // The three questions a task list actually asks: what is mine, what
            // is due, and what belongs to this engagement.
            $table->index(['office_id', 'status', 'due_at'], 'tasks_office_status_due_index');
            $table->index('assigned_to', 'tasks_assigned_to_index');
            $table->index('created_by', 'tasks_created_by_index');
            $table->index('project_id', 'tasks_project_index');
            $table->index('matter_id', 'tasks_matter_index');
            $table->index('priority', 'tasks_priority_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $statuses = implode("', '", TaskStatus::values());
            $priorities = implode("', '", array_map(
                static fn (ProjectPriority $case): string => $case->value,
                ProjectPriority::cases(),
            ));

            $connection->statement(
                "ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('{$statuses}'))"
            );

            $connection->statement(
                "ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('{$priorities}'))"
            );

            // A completed Task carries both facts or neither. Half of a
            // completion is a row nobody can explain — and the pair is what the
            // reopen path clears together.
            $connection->statement(
                'ALTER TABLE tasks ADD CONSTRAINT tasks_completion_pair_check '
                .'CHECK ((completed_at IS NULL AND completed_by IS NULL) '
                .'OR (completed_at IS NOT NULL AND completed_by IS NOT NULL))'
            );
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs
        // there. The enum casts and the model guard hold these on that
        // connection.
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
