<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chain of title (M7.1, D-121).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 16, with `office_id` added as the
 * composite-key carrier — the extension every junction since D-080 records rather
 * than makes quietly.
 *
 * ## `is_current` is kept, and D-116 does not apply to it
 *
 * M5.1 removed `is_current` from `document_versions` and replaced it with a pointer,
 * because exactly one version may be current and *"a unique index is a business rule
 * wearing an index's clothing"*. **That reasoning inverts here.**
 *
 * A Property legitimately has **several** current owners at once, each with an
 * `ownership_percentage`. `is_current` on this table is a *"this row applies now"*
 * flag on many rows, not a *"this is the one"* pointer on one — a different construct
 * that happens to share a name. So there is **no unique index on
 * `(property_id, is_current)`**, and adding one later would break co-ownership.
 *
 * What it does share with D-116 is the denormalization hazard: `is_current` is
 * derivable from `effective_until`, so the two can disagree. The model writes them
 * together and a CHECK below refuses the contradictory combination outright.
 *
 * ## No percentage sum is enforced
 *
 * Whether co-owners' shares must total 100 is a rule about Indonesian co-ownership,
 * and `CLAUDE.md` section 62 forbids inventing it. The column stores what the office
 * records. What *is* enforced is arithmetic: a share is between 0 and 100.
 *
 * ## History is added, never overwritten
 *
 * `CLAUDE.md` section 63: a change of ownership **closes** the previous row by
 * stamping `effective_until` and clearing `is_current`, and **inserts** a new one. It
 * never updates the old row's party or percentage. `source_matter_id` records which
 * transaction produced the row, which is the audit trail this table exists for.
 *
 * `source_matter_id` is `RESTRICT`, not `SET NULL`: nulling a composite key nulls
 * `office_id` too, which is `NOT NULL` — the M5.4 finding, and the reason the obvious
 * `nullOnDelete()` on a composite key is not merely undesirable but would fail at
 * runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_owners', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The composite carrier. Not in the canonical field list — recorded as
            // an extension, the same way `notary_matters` and `notary_minuta` record
            // theirs (D-120).
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            $table->ulid('property_id');
            $table->ulid('party_id');

            // Nullable: an office may record who owns a parcel without knowing the
            // split, and a sole owner needs no percentage at all.
            $table->decimal('ownership_percentage', 5, 2)->nullable();

            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            // Many rows may be true at once — see the class docblock.
            $table->boolean('is_current')->default(false);

            // The transaction that produced this row. Nullable because an office
            // records the ownership it inherits with the file, which predates any
            // Matter here.
            $table->ulid('source_matter_id')->nullable();

            $table->timestamps();

            // Plain keys so a row cannot point at a nonexistent record...
            $table->foreign('property_id', 'property_owners_property_foreign')
                ->references('id')->on('properties')->restrictOnDelete();

            $table->foreign('party_id', 'property_owners_party_foreign')
                ->references('id')->on('parties')->restrictOnDelete();

            $table->foreign('source_matter_id', 'property_owners_matter_foreign')
                ->references('id')->on('matters')->restrictOnDelete();

            // ...and the composite keys that make Office agreement structural. Every
            // one resolves through this table's own `office_id`, so none of them can
            // disagree with another. `RESTRICT` throughout: a chain of title does not
            // lose a link because somebody tidied a Party list.
            $table->foreign(['property_id', 'office_id'], 'property_owners_property_office_foreign')
                ->references(['id', 'office_id'])->on('properties')->restrictOnDelete();

            $table->foreign(['party_id', 'office_id'], 'property_owners_party_office_foreign')
                ->references(['id', 'office_id'])->on('parties')->restrictOnDelete();

            $table->foreign(['source_matter_id', 'office_id'], 'property_owners_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();

            // "Who owns this now" is the question this table is asked most.
            $table->index(['property_id', 'is_current'], 'property_owners_current_index');
            $table->index('party_id', 'property_owners_party_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            // A share is arithmetic, not a legal rule. No sum is enforced.
            $connection->statement(
                'ALTER TABLE property_owners ADD CONSTRAINT property_owners_percentage_check '
                .'CHECK (ownership_percentage IS NULL '
                .'OR (ownership_percentage >= 0 AND ownership_percentage <= 100))'
            );

            // A period runs forwards.
            $connection->statement(
                'ALTER TABLE property_owners ADD CONSTRAINT property_owners_period_check '
                .'CHECK (effective_until IS NULL OR effective_until >= effective_from)'
            );

            // The denormalization guard: a row that has ended cannot also be
            // current. This is what keeps `is_current` honest against
            // `effective_until` — see the class docblock.
            $connection->statement(
                'ALTER TABLE property_owners ADD CONSTRAINT property_owners_current_check '
                .'CHECK (NOT (is_current AND effective_until IS NOT NULL))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('property_owners');
    }
};
