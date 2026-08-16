<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual — the person subtype of Party (D-078).
 *
 * `party_id` is **both** primary key and foreign key. No surrogate id, so there
 * is no way to write two Individual rows for one Party and no way for an
 * Individual to exist without its Party.
 *
 * `party_type` is a pinned constant column, not data. It exists only to complete
 * the composite foreign key `(party_id, party_type) -> parties (id, party_type)`,
 * and a CHECK holds it at `INDIVIDUAL`. The effect is worth stating plainly,
 * because it is what upgrades three invariants from convention to enforcement:
 *
 *   1. this row's Party must have `party_type = 'INDIVIDUAL'`;
 *   2. a Party already holding a Company row therefore cannot hold one here —
 *      its `party_type` can only be one value;
 *   3. `parties.party_type` cannot be changed while this row exists, because the
 *      update would break the reference.
 *
 * `cascadeOnDelete` is right here in a way it is not for Office: a subtype
 * cannot outlive its aggregate root, so a hard delete of a Party must take it.
 * Ordinary archiving never reaches this — it sets `parties.deleted_at` (D-081).
 *
 * NIK and NPWP are encrypted at rest by the model's casts. They carry **no**
 * unique constraint and no index: a randomized ciphertext is useless to index,
 * and uniqueness on an optional, sometimes-mistyped, Office-scoped identifier
 * would assert something untrue and leak a cross-office existence oracle
 * (D-084). No CHECK validates their format — no canonical document freezes it,
 * and a guess would reject real identifiers (D-082).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('individuals', function (Blueprint $table): void {
            $table->ulid('party_id')->primary();

            // Pinned constant completing the composite foreign key below.
            $table->string('party_type', 20)->default('INDIVIDUAL');

            $table->foreign(['party_id', 'party_type'], 'individuals_party_composite_foreign')
                ->references(['id', 'party_type'])
                ->on('parties')
                ->cascadeOnDelete();

            // The only structurally required field: a directory row with no name
            // is not usable. Nothing here is required on legal grounds.
            $table->string('full_name');

            $table->string('prefix', 50)->nullable();
            $table->string('suffix', 50)->nullable();

            // Sensitive. Encrypted by the model cast, so the column must hold
            // ciphertext far longer than the plaintext identifier.
            $table->text('nik')->nullable();
            $table->text('npwp')->nullable();

            $table->string('birth_place')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('occupation')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('marital_status', 30)->nullable();

            $table->string('address')->nullable();
            $table->string('village')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code', 20)->nullable();

            $table->timestamps();
        });

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => Schema::getConnection()->statement(
                "ALTER TABLE individuals ADD CONSTRAINT individuals_party_type_check CHECK (party_type = 'INDIVIDUAL')"
            ),
            default => null,
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('individuals');
    }
};
