<?php

use App\Domains\Ppat\Enums\PropertyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The land object (M7.1, D-121).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 16. Nothing is added but the
 * `UNIQUE (id, office_id)` support key, which is created **here rather than by a
 * later ALTER** — the M4.2 construction, and the correction M6.3 had to make
 * because M6.1 had not anticipated the reference (M7 lock section 3.4).
 *
 * ## A Property is office-owned reference data, not work
 *
 * It exists before any Matter names it and outlives every one of them, which is why
 * its Data Scope is `OFFICE` and `ALL` only — the Party (D-080) and Service Type
 * (D-106) answer rather than the Project (D-088) one. See `PropertyVisibility`.
 *
 * ## Two vocabularies, and only one of them is closed
 *
 * The ERD gives `property_type` as a flat list of four values and introduces
 * `right_type` with *"Right type **may** use stable machine codes, **for
 * example**"*. That wording is the whole difference:
 *
 * - **`property_type` is CHECK-constrained.** Four values, closed list.
 * - **`right_type` is a plain `VARCHAR` with no CHECK.** Constraining it to the
 *   five examples would assert that Indonesian land law has five kinds of right,
 *   which `11_LEGAL_REFERENCES.md` exists as a statutory register precisely because
 *   nobody here may decide (`CLAUDE.md` section 62).
 *
 * Neither is translated in the database — the ERD says so outright.
 *
 * ## `status` has no vocabulary, so none is invented
 *
 * The ERD names the column and gives it **no values**. It is created nullable, with
 * no default and no CHECK, and nothing writes it — the `notary_minuta.release_status`
 * ruling at M6.3 (D-120). A default of `ACTIVE` would assert a lifecycle;
 * `properties.archive` is a canonical capability whose *meaning* is undefined until
 * somebody says what archiving a land object does.
 *
 * ## `property_number` is nullable, and M7.3 decides how it is filled
 *
 * The M7 lock section 15 recorded this as M7.1's own first question. The answer is
 * the one M4.2 and M5.1 both reached: **the column arrives nullable and the
 * allocator arrives with the creation path.** M7.1 ships no route, so nothing here
 * *can* allocate, and a `NOT NULL` column with no creation path would make the table
 * unusable by its own tests.
 *
 * Unique **per Office** where present, following D-103: an internal reference
 * identifies a record within its Office and does not identify it globally. Note that
 * `CLAUDE.md` section 38 shows `PROP-000001` **without a year**, unlike every other
 * internal reference — so whatever M7.3 builds is namespaced by Office alone, not by
 * Office and calendar year like D-108's allocator.
 *
 * **`certificate_number` is the legal identifier and `property_number` is not.** They
 * are different concepts in the way D-103 separated `matter_number` from a deed
 * number: one is ordinary office identification, the other is what the land office
 * issued. `certificate_number` is deliberately **not** unique — two offices may hold
 * records of the same certificate, and a certificate may be reissued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The security boundary and the OFFICE scope predicate.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // Nullable until a creation path exists to stamp it. See the class
            // docblock.
            $table->string('property_number', 50)->nullable();

            // Closed list, CHECK-constrained below.
            $table->string('property_type', 30);

            // Open list. No CHECK — see the class docblock.
            $table->string('right_type', 30);

            $table->string('certificate_number', 100);
            $table->date('certificate_date')->nullable();

            // Square metres. `decimal` rather than float: an area is a measurement
            // somebody wrote on a certificate, and binary floating point would round
            // it to something that is not what the certificate says.
            $table->decimal('land_area', 15, 2)->nullable();
            $table->decimal('building_area', 15, 2)->nullable();

            $table->string('measurement_letter_number', 100)->nullable();
            $table->date('measurement_letter_date')->nullable();

            $table->text('address');
            $table->string('village')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code', 20)->nullable();

            // Coordinates, if the office has surveyed them. 7 decimal places is
            // roughly a centimetre, which is well past what a land certificate
            // records and costs nothing to store.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Canonical column, no vocabulary, nothing writes it.
            $table->string('status', 30)->nullable();

            $table->timestamps();

            // Attribution must survive the person who typed it (D-050).
            $table->foreignUlid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            // The ERD carries it, and unlike `notary_deeds` a Property is not a
            // finalized legal record — it is reference data an office may retire.
            // `properties.archive` is the canonical capability; what it does is
            // M7.3's question.
            $table->softDeletes();

            // An internal reference identifies a record within its Office (D-103).
            // NULLs are distinct in a unique index on both connections, so any
            // number of unnumbered properties coexist.
            $table->unique(['office_id', 'property_number'], 'properties_office_number_unique');

            // The support key `property_owners`, `matter_properties` and every other
            // referencing table needs. Created here, not by a later ALTER.
            $table->unique(['id', 'office_id'], 'properties_id_office_id_unique');

            // The questions a property list actually asks.
            $table->index(['office_id', 'property_type'], 'properties_office_type_index');
            $table->index('certificate_number', 'properties_certificate_index');
            $table->index(['city', 'district'], 'properties_locality_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $types = implode("', '", PropertyType::values());

            $connection->statement(
                "ALTER TABLE properties ADD CONSTRAINT properties_type_check CHECK (property_type IN ('{$types}'))"
            );

            // **No `right_type` CHECK and no `status` CHECK**, deliberately. See the
            // class docblock: the ERD calls the right-type codes examples, and gives
            // `status` no values at all.

            // An area is not negative. Not a legal rule — arithmetic.
            $connection->statement(
                'ALTER TABLE properties ADD CONSTRAINT properties_area_check '
                .'CHECK ((land_area IS NULL OR land_area >= 0) '
                .'AND (building_area IS NULL OR building_area >= 0))'
            );
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs there.
        // The enum cast and the model guard hold these on that connection.
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
