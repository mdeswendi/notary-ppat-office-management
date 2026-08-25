<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Properties a Matter concerns (M7.1, D-121).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 16 — the junction the M7 brief
 * omitted and the lock's section 7.4 restored.
 *
 * **A surrogate `id`, not a composite primary key.** The ERD gives this table an
 * `id`, unlike `ppat_warkah_documents` and the M5 document junctions which have none.
 * Transcription follows the ERD rather than the pattern, and uniqueness of the pair
 * is asserted by an index instead.
 *
 * **`created_at` only.** The canonical list carries no `updated_at`, which is honest
 * for a row whose only mutable field is `role_code` — and D-105 made the same call
 * for `matter_parties`. `$table->timestamps()` would add a column the ERD does not
 * name.
 *
 * **`role_code` is a plain `VARCHAR` with no CHECK.** The ERD says *"**Example** role
 * codes"*, exactly as it says *"may use"* of `properties.right_type`. Constraining it
 * to three would assert that a Property relates to a Matter in three ways.
 *
 * **`office_id` is the composite carrier**, not in the canonical list, recorded here
 * as the extension every junction since D-080 records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_properties', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            $table->ulid('matter_id');
            $table->ulid('property_id');

            // Open list — see the class docblock.
            $table->string('role_code', 50)->nullable();

            // The ERD names `created_at` and no `updated_at`.
            $table->timestamp('created_at')->nullable();

            $table->foreign('matter_id', 'matter_properties_matter_foreign')
                ->references('id')->on('matters')->restrictOnDelete();

            $table->foreign('property_id', 'matter_properties_property_foreign')
                ->references('id')->on('properties')->restrictOnDelete();

            // The Office invariant, resolving through this table's own carrier.
            $table->foreign(['matter_id', 'office_id'], 'matter_properties_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();

            $table->foreign(['property_id', 'office_id'], 'matter_properties_property_office_foreign')
                ->references(['id', 'office_id'])->on('properties')->restrictOnDelete();

            // **One row per pair.** Unlike the M5 document junctions, which
            // deliberately left duplicates representable because no canonical
            // document stated a cardinality (D-116, D-118), naming the same Property
            // twice on one Matter says nothing a second time — `role_code` is what
            // would differ, and a Property that is both the transaction object and
            // the collateral is one relationship an office would describe in the
            // code, not two rows.
            $table->unique(['matter_id', 'property_id'], 'matter_properties_pair_unique');

            $table->index('property_id', 'matter_properties_property_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_properties');
    }
};
