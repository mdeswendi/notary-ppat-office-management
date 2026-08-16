<?php

use App\Domains\Project\Actions\CreateProject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `projects.project_number` becomes NOT NULL (M3.3).
 *
 * M3.2 left it nullable **deliberately** and said why: the allocator existed but
 * no product creation path did, so `NOT NULL` would have made Project unwritable
 * for a whole milestone. M3.3 ships creation, and every created Project is
 * stamped by {@see CreateProject} inside its
 * transaction. The invariant the column always wanted is now true, so the
 * database is told.
 *
 * **A separate forward migration, never an edit to M3.2's.** The accepted
 * migration stays exactly as it was accepted; tightening is its own recorded step
 * (D-097).
 *
 * **The persistent development database was inspected before this was written**,
 * as the milestone required rather than assumed: `projects` held 0 rows and 0
 * rows with a null reference. Nothing is backfilled and nothing is destroyed —
 * had a null-reference Project existed, the correct action was to stop and
 * report, because inventing a historical reference is exactly the `MAX+1`
 * guessing D-094 forbids.
 *
 * The composite `UNIQUE (office_id, project_number)` from M3.2 is unaffected and
 * is asserted to survive, which matters on SQLite where a column change is a
 * table rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('project_number', 32)->nullable(false)->change();
        });
    }

    /**
     * Back to nullable. Rolling this back does not delete any reference — it only
     * stops the database insisting on one, which is the M3.2 state.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('project_number', 32)->nullable()->change();
        });
    }
};
