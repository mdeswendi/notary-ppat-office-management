<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single per-user authorization exception mechanism (D-029).
 *
 * Roles remain the normal way to grant access. This table exists for the case a
 * role cannot express: one person, one permission, temporarily different.
 *
 * Spatie's own `model_has_permissions` stays untouched as package
 * infrastructure, but it is deliberately **not** a second grant path — two
 * competing per-user mechanisms would make precedence ambiguous, and ambiguity
 * in an authorization path is a defect. `EffectiveAccessResolver` reads this
 * table and never that one.
 *
 * Fields follow docs/03_DATABASE_ERD.md section 5, including `created_at` with
 * **no `updated_at`**: the canonical field list names only the former. An
 * override is a decision, and a decision that changes is a new decision.
 *
 * `scope` is nullable because DENY needs no scope to deny. That makes
 * `effect = ALLOW, scope = NULL` representable and meaningless, so the resolver
 * treats it as denied rather than as unrestricted — see
 * EffectiveAccessResolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');

        Schema::create('user_permission_overrides', function (Blueprint $table) use ($tableNames): void {
            $table->ulid('id')->primary();

            // CASCADE: an override for a user who no longer exists grants
            // nothing to nobody.
            $table->foreignUlid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('permission_id')
                ->constrained($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->string('effect', 10);

            // Nullable by design: DENY carries no scope. ALLOW without one is
            // malformed and fails closed at resolution.
            $table->string('scope', 20)->nullable();

            // NULL means the override does not expire. Comparison happens at
            // check time (D-029); correctness never depends on a cleanup job
            // having run.
            $table->timestamp('expires_at')->nullable();

            // RESTRICT, unlike `user_id` above. That column points at the
            // *subject* of the override, this one at its *author*, and
            // provenance should not disappear quietly. The registry defines no
            // `users.delete` permission at all, so this mainly makes that stance
            // explicit at the database level.
            $table->foreignUlid('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('created_at')->nullable();

            // At most one override row per user per permission, so "the active
            // override" is never a question about which row wins.
            $table->unique(['user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
