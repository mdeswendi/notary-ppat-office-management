<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Laravel scaffold created `users.id` as an auto-incrementing bigint. The
 * canonical key strategy for our own domain tables is ULID — CLAUDE.md
 * section 11, docs/03_DATABASE_ERD.md section 2, docs/10_M0_FOUNDATION.md
 * section 45 — and `users` is listed in the canonical ERD as one of ours, not
 * as a third-party package table.
 *
 * This scaffold migration was corrected in place rather than layered with a
 * bigint-to-ULID conversion. See docs/DECISIONS.md D-023 for why that
 * exception was made, and note that it applies to this pre-release correction
 * only.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // Must match the users key type. A bigint here would silently fail
            // to store a ULID once the session handler writes Auth::id().
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
