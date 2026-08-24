<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What people said while doing the work (M5.4, D-119).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 15 exactly: `id`, `task_id`,
 * `user_id`, `comment`, and the three timestamps. **No `office_id`**, and its
 * absence is the ERD's rather than an omission here — a comment is reached only
 * through its Task, which carries the Office, so a carrier column would be a
 * second answer to a question the parent already answers.
 *
 * That has a consequence worth stating: the same-Office invariant on a comment is
 * **inherited, not structural.** `task_id` is a plain foreign key, because there
 * is no pair to make composite. The Policy reaches a comment through the Task, so
 * the boundary holds where it is enforced — but a direct database write could
 * attach a comment to a Task in another Office, which the document junctions would
 * have refused. The ERD's shape is followed rather than improved on.
 *
 * **`task_id` cascades.** A comment has no existence apart from its Task; it is
 * not a record the office keeps separately. That differs from every other
 * relationship in this milestone, which restricts — because those relate two
 * records that each stand alone, and this relates a record to its own remarks.
 *
 * **`user_id` restricts.** Attribution survives the person who typed it (D-050),
 * which is why no `users` row can be removed while their words are on file.
 *
 * `deleted_at` is present because the ERD lists it. **M5.4 builds no deletion
 * path for a comment** — the model takes `SoftDeletes` so the column has a
 * lifecycle rather than sitting reserved, and the surface that decides whether a
 * person may retract a remark is not this milestone's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('task_id')
                ->index()
                ->constrained('tasks')
                ->cascadeOnDelete();

            $table->foreignUlid('user_id')
                ->index()
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('comment');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
