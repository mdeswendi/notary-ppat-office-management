<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The support key `notary_minuta` needs (M6.3, D-120).
 *
 * A composite foreign key requires a unique index on **exactly** the columns it
 * references, so `notary_minuta (notary_deed_id, office_id) -> notary_deeds
 * (id, office_id)` cannot be written until `notary_deeds` carries
 * `UNIQUE (id, office_id)`.
 *
 * **M6.1 did not add it, and that was right at the time.** No table referenced
 * `notary_deeds` then, and building a support key for an invariant no milestone
 * owned would have been construction ahead of requirement — the reasoning M4.2 gave
 * for not adding a `(pic_user_id, office_id)` key to `users` until M5.4 actually
 * needed one.
 *
 * Its own forward migration rather than folded into the table below, following
 * `2026_08_25_090000_add_user_office_support_key`: altering an existing table and
 * creating a new one are two changes, and a reader tracing why `notary_deeds`
 * gained an index should find a migration whose name says so.
 *
 * **Not a behaviour change.** `notary_deeds.id` is already a primary key, so this
 * index rejects nothing that was previously accepted — it exists so PostgreSQL will
 * accept the reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notary_deeds', function (Blueprint $table): void {
            $table->unique(['id', 'office_id'], 'notary_deeds_id_office_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notary_deeds', function (Blueprint $table): void {
            $table->dropUnique('notary_deeds_id_office_id_unique');
        });
    }
};
