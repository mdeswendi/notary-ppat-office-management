<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authentication-foundation columns from docs/10_M0_FOUNDATION.md section 44.
 *
 * `office_id` is deliberately absent: no offices table exists yet, and a
 * foreign key pointing at nothing would be worse than adding it in the correct
 * migration order later. Roles and permissions belong to M0.8.
 *
 * `after()` is omitted so the migration behaves identically on PostgreSQL and
 * on the in-memory SQLite used by the test suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Stable locale code, validated in the application layer rather
            // than pinned by a database enum — see CLAUDE.md section 13.
            $table->string('preferred_locale', 5)->default('id');

            // Disabled accounts must not be able to authenticate.
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['preferred_locale', 'is_active', 'last_login_at']);
        });
    }
};
