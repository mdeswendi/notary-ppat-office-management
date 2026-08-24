<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `UNIQUE (id, office_id)` on `users` (M5.4, D-119).
 *
 * **A support key, not a uniqueness rule.** `id` is already the primary key, so
 * this adds no constraint anybody can violate. What it adds is the unique index a
 * *composite* foreign key requires on the exact pair it references — PostgreSQL
 * refuses `REFERENCES users (id, office_id)` without one.
 *
 * Every office-owned table since M2 carries the same key for the same reason:
 * `parties` (D-080), `projects` (D-098), `service_types` (D-106), `matters`
 * (D-107), `documents` (D-116). `users` was the one participant in that pattern
 * that never needed it, because nothing had yet pointed at a user *and* an Office
 * together.
 *
 * **M5.4 is where that changes.** A Task names four users — `assigned_to`,
 * `assigned_by`, `created_by`, `completed_by` — and each must belong to the Task's
 * own Office. Without this key the boundary could only be validated; with it, a
 * cross-office assignment is **unrepresentable**, which is the difference the M2
 * lock drew and every milestone since has kept.
 *
 * **This touches an M1 table, deliberately and by decision.** The alternative was
 * a plain foreign key plus a same-Office check in the Request, and that check
 * would hold only for as long as every future write path remembered it. A
 * structural invariant needs no remembering.
 *
 * `users.office_id` is `NOT NULL` (D-027), so no row is exempt and the index has
 * no NULL semantics to reason about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['id', 'office_id'], 'users_id_office_id_unique');
        });
    }

    /**
     * Dropping it is safe only while nothing references the pair. The task
     * migrations that do are later, so a rollback removes them first.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_id_office_id_unique');
        });
    }
};
