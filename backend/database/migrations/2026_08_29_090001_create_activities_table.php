<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened, for people to read (M8.1, D-123).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 24. Batch 7, alongside
 * `audit_logs`, and owed since the same ordering argument deferred both.
 *
 * ## Why this is a second table and not a view over `audit_logs`
 *
 * `18_M8_...ARCHITECTURE.md` section 2 keeps them apart, and the ERD gives them
 * separate sections with different shapes. An activity row says *"a document was
 * uploaded to this Matter"* and is read by users on a timeline; an audit row says
 * *"this actor changed this field from A to B at this IP"* and is read by an
 * auditor. Merging them would give the timeline audit's immutability burden and
 * give audit the timeline's presentation concerns.
 *
 * **The authorization consequence is the load-bearing one.** `audit_logs` answers
 * to `audit.view`. A dashboard activity feed reading from it would either be
 * denied to every actor without that capability, or would become a way to read
 * audit content without holding it — which D-122 forbids by name. This table has
 * **no capability at all**, and is read per row by the visibility of its subject
 * (O-047). That is what lets an ordinary staff dashboard carry a feed.
 *
 * ## `description_key` is a translation key, never a sentence
 *
 * `CLAUDE.md` section 6 applies to the timeline exactly as it applies to
 * everything else, and section 12 applies to `activity_type`: stable machine
 * codes, with the label resolved in the presentation layer. `metadata` carries
 * the key's interpolation values and nothing else — never a NIK, never an NPWP,
 * never a filename that might carry one (D-105).
 *
 * ## No `updated_at`, and no soft delete
 *
 * The ERD lists `created_at` alone. A timeline entry describes a moment that
 * either happened or did not; there is nothing to amend and nothing to withdraw.
 * Unlike `audit_logs` this is not stated as an append-only *rule* in the ERD, but
 * the field list says the same thing and the model enforces it the same way.
 *
 * ## `subject_type` / `subject_id` are polymorphic; `project_id` and `matter_id` are not
 *
 * The ERD carries all four. The first pair names what the activity is *about*;
 * the second pair is denormalised context so a Project or Matter timeline is one
 * indexed query rather than a union across every subject type. Both are nullable
 * because plenty of activity belongs to neither — a Party edit, a standalone Task.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // **Plain, not composite**, for the same reason as
            // `audit_logs.actor_user_id`: `office_id` is the *subject's* Office,
            // and an actor holding Data Scope `ALL` acts across Offices by
            // design. Pairing the two would make "somebody from head office
            // approved this deed" unrepresentable.
            //
            // The `project_id` and `matter_id` keys below stay composite,
            // because those describe the subject's own context and must agree
            // with the row's Office.
            $table->ulid('actor_user_id')->nullable();

            $table->string('activity_type', 60);

            $table->string('subject_type');
            $table->string('subject_id', 40);

            // Denormalised context. Nullable, and plain keys rather than
            // composite ones would be enough for correctness — the composite
            // pair is what makes a cross-office row unrepresentable.
            $table->ulid('project_id')->nullable();
            $table->ulid('matter_id')->nullable();

            $table->string('description_key');
            $table->jsonb('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('actor_user_id', 'activities_actor_foreign')
                ->references('id')->on('users')->restrictOnDelete();

            $table->foreign(['project_id', 'office_id'], 'activities_project_office_foreign')
                ->references(['id', 'office_id'])->on('projects')->restrictOnDelete();

            $table->foreign(['matter_id', 'office_id'], 'activities_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();

            // The feed's own query, then the three timelines that embed it.
            $table->index(['office_id', 'created_at'], 'activities_office_created_index');
            $table->index(['subject_type', 'subject_id'], 'activities_subject_index');
            $table->index('project_id', 'activities_project_index');
            $table->index('matter_id', 'activities_matter_index');
            $table->index('actor_user_id', 'activities_actor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
