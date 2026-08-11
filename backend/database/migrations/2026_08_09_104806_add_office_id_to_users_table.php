<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every operational user belongs to exactly one primary Office (D-027).
 *
 * The column is NON-NULL. M0 deliberately omitted it rather than point a
 * foreign key at a table that did not exist, and the `users` table holds no
 * persistent row, so the relationship can be established directly without a
 * nullable interim phase or a fabricated placeholder Office.
 *
 * No `organization_id` is added here: the Organization is derived through
 * User → Office → Organization. Duplicating it on `users` would create a second
 * source of truth that could disagree with the Office.
 *
 * There is no `user_offices` pivot. Cross-office access is a Data Scope
 * concern, not a membership one — one primary Office keeps the `OFFICE` scope
 * answerable with a single comparison.
 *
 * `after()` is omitted so the migration behaves identically on PostgreSQL and
 * on the in-memory SQLite the test suite uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The index is declared explicitly: PostgreSQL does not create one
            // for a referencing column, unlike MySQL, and every office-scoped
            // query will filter on it.
            //
            // `restrictOnDelete` prevents an Office removal from silently
            // deleting the people who work there.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('office_id');
        });
    }
};
