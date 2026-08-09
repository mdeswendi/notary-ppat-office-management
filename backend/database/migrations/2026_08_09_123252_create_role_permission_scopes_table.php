<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data Scope metadata for a role's permission grant.
 *
 * Spatie answers "does this role have this permission". It has no opinion about
 * *which records* the grant reaches, and that is the question every legal-office
 * Policy will actually ask. This table supplies the missing half, following
 * docs/03_DATABASE_ERD.md section 5.
 *
 * A role holds **at most one** Data Scope for a given permission, hence
 * UNIQUE (role_id, permission_id). That does not weaken D-028: the union of
 * scopes happens *across* the several roles one user holds, not within a single
 * role's grant.
 *
 * Key types are deliberately mixed. `id` is a ULID because the table is ours
 * (CLAUDE.md section 11), while `role_id` and `permission_id` stay
 * `unsignedBigInteger` to match Spatie's package-native `$table->id()` keys.
 * Converting the package's keys would mean editing vendor migrations, which
 * D-023 already ruled out for exactly this reason.
 *
 * Table names come from `config('permission.table_names')` rather than string
 * literals, so a future rename in the package config cannot leave these foreign
 * keys pointing at tables that no longer exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');

        Schema::create('role_permission_scopes', function (Blueprint $table) use ($tableNames): void {
            $table->ulid('id')->primary();

            // CASCADE, unlike the RESTRICT used across M1.1. This is derived
            // authorization metadata, not a legal record: if a role or a
            // permission is genuinely deleted, a scope row describing it
            // describes nothing, and an orphan row in an authorization table is
            // worse than no row. Legal relationships keep RESTRICT.
            $table->foreignId('role_id')
                ->constrained($tableNames['roles'])
                ->cascadeOnDelete();

            $table->foreignId('permission_id')
                ->constrained($tableNames['permissions'])
                ->cascadeOnDelete();

            // VARCHAR carrying a stable machine code, backed by the DataScope
            // PHP enum, per CLAUDE.md section 13. Not a PostgreSQL native ENUM:
            // adding a scope value would otherwise need an ALTER TYPE.
            $table->string('scope', 20);

            $table->timestamps();

            // One scope per role per permission. Also the index the resolver
            // reads through — it filters `role_id IN (...) AND permission_id = ?`,
            // which this covers leftmost-first. No further index is added,
            // because no query exists yet that this one does not serve.
            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission_scopes');
    }
};
