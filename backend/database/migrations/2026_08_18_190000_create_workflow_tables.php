<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow templates and their stages (M4.6, D-104, D-111).
 *
 * **A mechanism, shipped deliberately empty.** Both tables are created and
 * nothing seeds them. `08_NOTARY_WORKFLOW.md` and `09_PPAT_WORKFLOW.md` are
 * `DRAFT — DOMAIN VALIDATION REQUIRED` and state that no workflow content has
 * been authored and none may be inferred from other documents here, so M4 builds
 * the engine from `03_DATABASE_ERD.md` section 11 and stops (D-104). **No stage
 * sequence, no default template, no approval point, no required-before-stage
 * rule, no tax or deed gating, and no legal completion condition is created.**
 * An office's real workflow is blocked on domain validation, not on engineering,
 * and when it arrives it should be configuration rather than a schema change.
 *
 * **Backend foundation only**, following M2.1, M3.1, M4.1 and M4.2: no route, no
 * controller, no request, no resource, no seeder, no frontend. The two
 * permissions these tables answer to — `master.workflows.view` and
 * `master.workflows.manage` — were already canonical, so **the count stays at
 * 177**; M4.6 only narrows their assignable Data Scopes.
 *
 * ## Versioning, which is the decision this migration turns on
 *
 * `UNIQUE (office_id, code)` — **one row per code, and `version` is a counter on
 * it** (D-111). Editing a template bumps `version` in place; there is no second
 * row for the older version and none is wanted. That is why the ERD gives
 * `matter_workflows` **both** `workflow_template_id` *and* `workflow_version`:
 * the id says which template a Matter is running, the number says which
 * iteration of it, and the content of that iteration is preserved by M4.7's
 * snapshot — `stage_code` plus both snapshot names on every stage instance.
 * `CLAUDE.md` section 18 requires exactly that: editing a template must never
 * retroactively change a Matter already running, and a snapshot is what
 * guarantees it. Storing each version as its own row would make
 * `workflow_version` redundant with the foreign key and would multiply the
 * catalogue an office has to read.
 *
 * ## What is enforced here and what is not
 *
 * Enforced: Office ownership, same-Office binding to a Service Type, code
 * uniqueness within its namespace, one stage per position, and three
 * non-negativity CHECKs.
 *
 * **Not enforced, and deliberately:** that exactly one template is the default.
 * `is_default` is a designation under no cardinality rule, following
 * `project_parties.is_primary` (D-092) and D-105 — several may be true at once
 * and none has to be. No canonical document says otherwise, and a partial unique
 * index would be a business rule nobody wrote, which additionally does not exist
 * on the SQLite test connection. **M4.7 must therefore choose deterministically
 * and say how**, rather than assuming the database handed it exactly one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Office ownership is the security boundary and the OFFICE scope
            // predicate. `restrictOnDelete` matches `service_types.office_id`:
            // removing an Office must never silently take its workflow
            // configuration with it. Indexed explicitly — PostgreSQL does not
            // index a referencing column just because it carries a foreign key.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // **Nullable, and the nullability is the feature.** A template bound
            // to a Service Type configures that service; an unbound one is the
            // office's generic process. Requiring it would make workflow
            // configuration impossible for as long as the service catalogue is
            // empty — and M4.1 ships it empty on purpose (D-102, D-106).
            $table->ulid('service_type_id')->nullable();

            $table->index('service_type_id', 'workflow_templates_service_type_index');

            $table->foreign('service_type_id', 'workflow_templates_service_type_foreign')
                ->references('id')->on('service_types')->restrictOnDelete();

            // The same-Office invariant, made structural rather than merely
            // validated (D-111). Office A's template cannot bind Office B's
            // service, because both endpoints resolve through this table's own
            // `office_id`. The composite key a nullable column takes part in is
            // satisfied when the column is NULL, so a generic template stays
            // valid — the same property `matters.service_type_id` relies on
            // (D-107).
            //
            // `service_types` has carried the matching `UNIQUE (id, office_id)`
            // support key since M4.1, which added it in anticipation of exactly
            // this, so no support key is added here.
            $table->foreign(['service_type_id', 'office_id'], 'workflow_templates_service_type_office_foreign')
                ->references(['id', 'office_id'])->on('service_types')->restrictOnDelete();

            // A stable configuration handle entered by the office. Not legal
            // numbering, not an internal reference, and carrying no sequence,
            // year, or legal weight (D-103). Stored exactly as submitted: no case
            // normalization, because no canonical document defines one and
            // inventing it would silently make `STANDARD` and `standard` the same
            // code, or fail to, with no stated rule either way (the O-023 shape).
            $table->string('code', 50);

            // Bilingual master content, sanctioned by CLAUDE.md section 10. Both
            // required: a template that cannot be displayed in one of the two
            // supported locales is incomplete, and falling back silently would
            // hide that.
            $table->string('name_id');
            $table->string('name_en');

            // A counter on this row, not a second row (see the class docblock).
            // `unsignedInteger` does **not** make this positive on PostgreSQL,
            // which has no unsigned integer type and silently maps it to
            // `integer` — the M4.1 `default_duration_days` lesson, which the
            // disposable-database run proved by accepting -1. The CHECK below is
            // what actually enforces it.
            $table->unsignedInteger('version')->default(1);

            // A designation under no cardinality rule. See the class docblock.
            $table->boolean('is_default')->default(false);

            // The only retirement mechanism, exactly as for Service Types: an
            // inactive template is unavailable for new instantiation and stays
            // readable on every Matter already running it. **No `deleted_at`** —
            // nothing here is soft-deleted, so "invisible because retired" can
            // never be confused with "invisible because deleted".
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // A code identifies one template **within its Office**. Composite,
            // never global: two Offices may both run a `STANDARD`, and a global
            // index would fail the second office's first entry for no explicable
            // reason.
            $table->unique(['office_id', 'code'], 'workflow_templates_office_id_code_unique');

            // The support key a composite foreign key needs, added now rather
            // than by a later ALTER — the M4.1 habit, and M4.7's
            // `matter_workflows` is the caller that will want it, so that a
            // Matter cannot run another Office's template.
            $table->unique(['id', 'office_id'], 'workflow_templates_id_office_id_unique');

            // Templates are read one Office at a time, usually narrowed to what
            // is still offered and often looking for the default.
            $table->index(['office_id', 'is_active', 'is_default'], 'workflow_templates_office_active_default_index');
        });

        Schema::create('workflow_stages', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // **CASCADE, and it is the one place this schema uses it.** A stage
            // has no existence apart from its template — it is not a record the
            // office keeps, it is a line inside a configuration — so orphaning
            // stages would leave rows nothing can reach or explain.
            //
            // The consequence is stated rather than discovered later: **M4.7's
            // `matter_stage_instances.workflow_stage_id` must be RESTRICT or
            // nullable**, or deleting a template would reach through this cascade
            // and damage the history of Matters that ran it. The snapshot columns
            // exist precisely so an instance survives its stage definition.
            $table->foreignUlid('workflow_template_id')
                ->index()
                ->constrained('workflow_templates')
                ->cascadeOnDelete();

            $table->string('code', 50);

            $table->string('name_id');
            $table->string('name_en');

            // Position within this template, and nothing else. **Unlike
            // `matter_parties.sequence_no`, which D-105 deferred as semantically
            // unvalidated, this one has a settled structural meaning**: it is the
            // order the engine reads stages in. It is not a signing order, not a
            // legal priority, and not an appearance order in a deed.
            //
            // Signed on PostgreSQL whatever Laravel's type name suggests, so the
            // CHECK below is what keeps it positive.
            $table->unsignedInteger('sequence_no');

            // Informational planning metadata only. **Not an SLA, not a legal
            // deadline, and not a statutory period** — no canonical document
            // defines any of those. Nullable because a stage may legitimately
            // have no target.
            $table->unsignedInteger('target_days')->nullable();

            // **Mechanism, not an approval point.** That a stage *can* require
            // approval is architecture; *which* stages require it, and from whom,
            // is content D-104 forbids inferring. Both columns ship empty.
            $table->boolean('requires_approval')->default(false);

            // A canonical permission code or NULL, and **the model refuses
            // anything else on save** (D-111). Left unconstrained this would be
            // an authorization surface configured by free text: a value naming no
            // registered code would be unresolvable, and M4.7 would have to
            // decide at runtime what an unknown string means. Validating at the
            // point of writing means the question never arises.
            //
            // Storing the code does not authorize anything by itself. Whatever
            // reads it must still go through `EffectiveAccessResolver` with the
            // actor's Data Scope, exactly as every other decision does (D-048).
            $table->string('approval_permission')->nullable();

            // Structural markers, both plain booleans under no cardinality rule
            // in M4.6. Which stage starts a process and which completes it is
            // configuration; **that a completion stage carries legal effect is
            // not decided here and must not be inferred** (D-104).
            $table->boolean('is_start_stage')->default(false);
            $table->boolean('is_completion_stage')->default(false);

            $table->timestamps();

            // A code identifies one stage within its template. Never global, and
            // never per Office: two templates in one Office may both have a
            // `REVIEW`, and they are different stages.
            $table->unique(['workflow_template_id', 'code'], 'workflow_stages_template_code_unique');

            // One stage per position. This is the engine's own consistency rather
            // than an invented business rule — two stages claiming position 3
            // leave "what comes next" undefined for the thing whose whole job is
            // answering it.
            //
            // Worth knowing before a template editor is built: PostgreSQL checks
            // unique constraints per statement, so swapping two stages' positions
            // needs one statement (a single `UPDATE ... CASE`), a temporary
            // out-of-range value, or a deferrable constraint. M4.6 ships no editor,
            // so the choice belongs to the milestone that does.
            $table->unique(['workflow_template_id', 'sequence_no'], 'workflow_stages_template_sequence_unique');
        });

        // Non-negativity and positivity, which Laravel's `unsigned*` types do not
        // provide: they are MySQL concepts, and PostgreSQL maps them to signed
        // columns without complaint. This is the M4.1 lesson applied to three more
        // columns before it can bite.
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement(
                'ALTER TABLE workflow_templates ADD CONSTRAINT workflow_templates_version_check '
                .'CHECK (version >= 1)'
            );

            // Nullable, so the constraint must permit NULL explicitly — the same
            // shape `service_types_default_duration_days_check` uses. A target
            // counts days; a negative one is not a shorter target, it is
            // meaningless data.
            $connection->statement(
                'ALTER TABLE workflow_stages ADD CONSTRAINT workflow_stages_target_days_check '
                .'CHECK (target_days IS NULL OR target_days >= 0)'
            );

            // Positions start at 1. Zero would be a position too, but the
            // uniqueness above makes the sequence readable only if everybody
            // agrees where it starts.
            $connection->statement(
                'ALTER TABLE workflow_stages ADD CONSTRAINT workflow_stages_sequence_no_check '
                .'CHECK (sequence_no >= 1)'
            );
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs
        // there. The model guards are what refuse an invalid value on that
        // connection — stated plainly rather than left to be discovered, exactly
        // as the `service_types`, `projects` and `parties` migrations say of
        // their own constrained columns.
    }

    public function down(): void
    {
        // Stages first: the cascade would take them anyway, but dropping in
        // dependency order keeps the rollback readable and does not rely on it.
        Schema::dropIfExists('workflow_stages');
        Schema::dropIfExists('workflow_templates');
    }
};
