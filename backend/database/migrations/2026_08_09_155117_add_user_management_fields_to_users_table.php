<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the `users` table against docs/03_DATABASE_ERD.md section 4.
 *
 * Only two columns were missing: `phone` and `deleted_at`. Everything else the
 * canonical field list names already exists, and `email_verified_at` is kept —
 * D-031 decided that deliberately, and this migration does not revisit it.
 *
 * `phone` is a plain nullable string. No formatting, country prefix, or
 * normalization is imposed: the specification defines none, and inventing one
 * would mean rewriting what an office typed. It stores what was entered.
 *
 * `deleted_at` is schema foundation, not a feature. The permission registry
 * defines no `users.delete`, so M1.5 exposes no deletion of any kind; the
 * column exists so that removing a person can never mean losing the record
 * their Minuta Akta and audit trail will reference (D-050). Accounts are
 * retired with `is_active`.
 *
 * Deliberately absent: `organization_id` (reached through the Office),
 * `role_id` (assignments live in the package pivot), `tenant_id`, and
 * `team_id`. No `user_offices` pivot — one primary Office per user (D-027).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 50)->nullable();

            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone');
            $table->dropSoftDeletes();
        });
    }
};
