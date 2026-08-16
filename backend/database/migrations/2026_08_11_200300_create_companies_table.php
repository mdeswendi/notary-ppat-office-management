<?php

use App\Domains\Party\Enums\CompanyEntityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company — the organization subtype of Party (D-078).
 *
 * Structurally the mirror of `individuals`: `party_id` as both primary key and
 * foreign key, plus a pinned `party_type` completing the composite reference
 * that makes the subtype invariants database-enforced. See that migration for
 * the full reasoning; it applies here unchanged with `COMPANY` in place of
 * `INDIVIDUAL`.
 *
 * `legal_name` and `entity_type` are required for structural reasons — a
 * directory row needs a name, and an organization with no legal form cannot be
 * displayed correctly. Neither is required on legal grounds, and no Indonesian
 * corporate requirement is encoded anywhere in this table.
 *
 * `tax_id` is the Company NPWP and receives exactly the protection the
 * Individual identifiers get: encrypted at rest, no index, no unique constraint,
 * no format CHECK (D-082, D-084).
 *
 * Deliberately absent: `status`, dropped by D-081 because archive is an
 * aggregate operation; and `phone` / `email`, dropped because they duplicated
 * `parties.primary_phone` and `parties.primary_email` with no independent
 * meaning — `individuals` never carried a pair, so keeping them here would also
 * have made the two subtypes gratuitously asymmetric.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->ulid('party_id')->primary();

            $table->string('party_type', 20)->default('COMPANY');

            $table->foreign(['party_id', 'party_type'], 'companies_party_composite_foreign')
                ->references(['id', 'party_type'])
                ->on('parties')
                ->cascadeOnDelete();

            $table->string('legal_name');
            $table->string('short_name')->nullable();

            // Stable code from the seven the ERD names. Transcribed, not extended.
            $table->string('entity_type', 30);

            $table->string('registration_number')->nullable();

            // Sensitive: the Company NPWP. Encrypted by the model cast.
            $table->text('tax_id')->nullable();

            $table->string('address')->nullable();
            $table->string('village')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code', 20)->nullable();

            $table->timestamps();
        });

        $entityTypes = implode("', '", CompanyEntityType::values());

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => Schema::getConnection()->statement(
                "ALTER TABLE companies ADD CONSTRAINT companies_party_type_check CHECK (party_type = 'COMPANY'), "
                ."ADD CONSTRAINT companies_entity_type_check CHECK (entity_type IN ('{$entityTypes}'))"
            ),
            default => null,
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
