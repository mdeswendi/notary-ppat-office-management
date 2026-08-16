<?php

use App\Domains\Party\Enums\PartyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Party — the aggregate root for every person and organization the office knows
 * (D-078).
 *
 * One row per Individual or Company. There is deliberately no `clients` table
 * and no `client_id`: a Party becomes a client through use, and freezing that
 * role into the master record is the mistake CLAUDE.md section 17 already
 * refuses for Party roles.
 *
 * Two unique keys beyond the primary key exist purely so that other tables can
 * make invariants structurally true rather than conventionally hoped for:
 *
 * `(id, party_type)`   lets `individuals` and `companies` carry a composite
 *                      foreign key that pins each subtype to the matching
 *                      `party_type`. That single constraint makes three things
 *                      impossible at the database level: a subtype whose kind
 *                      disagrees with its Party, one Party holding both an
 *                      Individual and a Company row, and — while any subtype row
 *                      exists — changing `party_type` at all.
 *
 * `(id, office_id)`    lets `company_people` carry composite foreign keys to
 *                      both endpoints through one shared `office_id`, which
 *                      makes a cross-office relationship unrepresentable rather
 *                      than merely discouraged (D-080).
 *
 * Neither is a second source of truth; both are the same columns re-indexed so
 * that PostgreSQL — and SQLite, which the test suite uses — can enforce what
 * would otherwise be application convention.
 *
 * Deliberately absent: `organization_id` (reached through the Office, D-027),
 * `tenant_id`, `client_id`, `party_offices`, and `status`. The last one was
 * dropped by D-081 because it competed with `deleted_at` for lifecycle
 * authority, and two answers to "is this archived" is how a record ends up
 * archived-but-visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Office ownership is the security boundary (D-080). `restrictOnDelete`
            // matches `users.office_id`: removing an Office must never silently
            // take its directory with it. Indexed explicitly — PostgreSQL does
            // not index a referencing column just because it carries a foreign key.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // Stable codes, never translated labels (CLAUDE.md section 12).
            $table->string('party_type', 20);

            // Derived and normalized, never a third independently editable name
            // (D-079). Synchronization is owned by the Create/Update Actions
            // that M2.2 and M2.3 introduce.
            $table->string('display_name');

            // Contact details belong to the aggregate, not to either subtype.
            // `companies.phone` / `companies.email` were dropped for exactly this
            // reason, and `individuals` never carried a pair to begin with.
            $table->string('primary_phone', 50)->nullable();
            $table->string('primary_email')->nullable();

            // Attribution must survive the person who typed it. `restrictOnDelete`
            // for the same reason as Office; no user-deletion path exists anyway
            // (D-050).
            $table->foreignUlid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // The single archive authority (D-081). Archiving is an aggregate
            // operation; subtypes are never independently soft-deleted.
            $table->softDeletes();

            // Constraint carriers for the subtype and relationship tables.
            $table->unique(['id', 'party_type'], 'parties_id_party_type_unique');
            $table->unique(['id', 'office_id'], 'parties_id_office_id_unique');

            // The directory always filters by Office first, usually narrows by
            // type, and sorts by name.
            $table->index(['office_id', 'party_type'], 'parties_office_id_party_type_index');
            $table->index('display_name', 'parties_display_name_index');
        });

        // Only the two canonical codes are storable. A CHECK rather than a
        // PostgreSQL native ENUM, per CLAUDE.md section 13 — the enum lives in
        // PHP, and the database refuses anything the enum does not name.
        $values = implode("', '", PartyType::values());

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => Schema::getConnection()->statement(
                "ALTER TABLE parties ADD CONSTRAINT parties_party_type_check CHECK (party_type IN ('{$values}'))"
            ),
            // SQLite cannot add a CHECK after the fact. The test suite runs there,
            // so the enum cast on the model is what refuses an invalid value —
            // stated plainly rather than left to be discovered.
            default => null,
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
