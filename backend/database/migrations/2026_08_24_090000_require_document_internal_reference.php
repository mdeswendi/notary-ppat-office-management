<?php

use App\Domains\Document\Actions\UploadDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `documents.document_number` becomes NOT NULL (M5.2, D-117).
 *
 * M5.1 left it nullable **deliberately** and said why: the allocator existed but
 * no product creation path did, so `NOT NULL` would have made a Document
 * unwritable for a whole milestone. M5.2 ships upload, and every uploaded
 * Document is stamped by {@see UploadDocument} inside its transaction. The
 * invariant the column always wanted is now true, so the database is told.
 *
 * **A separate forward migration, never an edit to M5.1's.** The accepted
 * migration stays exactly as it was accepted; tightening is its own recorded step
 * — the M3.2 → M3.3 and M4.3 → M4.4 precedent (D-097, D-109) followed a third
 * time.
 *
 * **The persistent development database was inspected before this was written**,
 * as the milestone required rather than assumed: it holds **no `documents` table
 * at all**, still standing at 22 migrations, so no Document row has ever existed
 * outside an in-memory test database or a disposable verification database.
 * Nothing is backfilled and nothing is destroyed — had a null-reference Document
 * existed, the correct action was to stop and report, because inventing a
 * historical reference is the `MAX+1` guessing D-103 forbids.
 *
 * The composite `UNIQUE (office_id, document_number)` and the
 * `UNIQUE (id, office_id)` support key the three junctions depend on are
 * unaffected and are asserted to survive, which matters on SQLite where a column
 * change is a table rebuild — and matters more here than it did for Matter,
 * because dropping that support key would silently take three composite foreign
 * keys with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('document_number', 32)->nullable(false)->change();
        });
    }

    /**
     * Back to nullable. Rolling this back does not delete any reference — it only
     * stops the database insisting on one, which is the M5.1 state.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('document_number', 32)->nullable()->change();
        });
    }
};
