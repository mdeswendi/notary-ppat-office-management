<?php

use App\Domains\Matter\Actions\CreateMatter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `matters.matter_number` becomes NOT NULL (M4.4, D-109).
 *
 * M4.3 left it nullable **deliberately** and said why: the allocator existed but
 * no product creation path did, so `NOT NULL` would have made Matter unwritable
 * for a whole milestone. M4.4 ships creation, and every created Matter is stamped
 * by {@see CreateMatter} inside its transaction. The invariant the column always
 * wanted is now true, so the database is told.
 *
 * **A separate forward migration, never an edit to M4.3's.** The accepted
 * migration stays exactly as it was accepted; tightening is its own recorded step
 * — the M3.2 → M3.3 precedent (D-097) followed exactly.
 *
 * **The persistent development database was inspected before this was written**,
 * as the milestone required rather than assumed: it holds **no `matters` table at
 * all**, still standing at 22 migrations, so no Matter row has ever existed
 * outside an in-memory test database or a disposable verification database.
 * Nothing is backfilled and nothing is destroyed — had a null-reference Matter
 * existed, the correct action was to stop and report, because inventing a
 * historical reference is exactly the `MAX+1` guessing D-103 forbids.
 *
 * The composite `UNIQUE (office_id, matter_number)` and the prefix CHECK from
 * M4.3 are unaffected and are asserted to survive, which matters on SQLite where
 * a column change is a table rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table): void {
            $table->string('matter_number', 32)->nullable(false)->change();
        });
    }

    /**
     * Back to nullable. Rolling this back does not delete any reference — it only
     * stops the database insisting on one, which is the M4.3 state.
     */
    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table): void {
            $table->string('matter_number', 32)->nullable()->change();
        });
    }
};
