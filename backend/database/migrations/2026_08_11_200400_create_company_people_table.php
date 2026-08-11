<?php

use App\Domains\Party\Enums\CompanyRelationshipType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an Individual relates to a Company, with history (D-083).
 *
 * Schema foundation only. M2.4 owns relationship management; M2.1 creates no
 * endpoint and no mutation action for this table.
 *
 * **Both endpoints are structurally constrained**, not merely named
 * conventionally:
 *
 *   `company_party_id`    -> `companies.party_id`   so it must be a Company
 *   `individual_party_id` -> `individuals.party_id` so it must be an Individual
 *
 * A relationship therefore cannot point at an arbitrary Party, and cannot point
 * the two endpoints at the same kind of thing.
 *
 * **The same-office invariant is enforced by the database** (D-080). `office_id`
 * here is a *constraint carrier*, not independent data: two composite foreign
 * keys reference `parties (id, office_id)` through the **same** column, so both
 * endpoints must agree with it and therefore with each other. A cross-office
 * relationship is unrepresentable rather than merely discouraged — which matters
 * because `ALL` authorization grants visibility, never permission to redefine
 * domain ownership.
 *
 * Person and company names are **never duplicated here**. The relationship points
 * at the Party; the name lives in one place and stays correct when it changes.
 *
 * History is represented by rows. A director change ends the existing row by
 * setting `effective_until` and inserts a new one — it never overwrites, because
 * "who was the director in March" must stay answerable for deeds executed in
 * March. There is deliberately **no `is_current` column**: it would duplicate
 * what `effective_until` already says, and the two would eventually disagree
 * invisibly (D-081). Current-ness is `effective_until IS NULL`, a query.
 *
 * No unique constraint: the same person may hold the same role twice across
 * different periods, which is history rather than duplication. And no invented
 * corporate-law rule — nothing here caps directors, requires a commissioner, or
 * makes shareholdings total 100%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_people', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->ulid('company_party_id');
            $table->ulid('individual_party_id');

            // Constraint carrier: pinned equal to both endpoints' Office by the
            // composite foreign keys below.
            $table->ulid('office_id');

            // Subtype enforcement.
            $table->foreign('company_party_id', 'company_people_company_foreign')
                ->references('party_id')->on('companies')->cascadeOnDelete();

            $table->foreign('individual_party_id', 'company_people_individual_foreign')
                ->references('party_id')->on('individuals')->cascadeOnDelete();

            // Same-office enforcement: both must match the one office_id.
            $table->foreign(['company_party_id', 'office_id'], 'company_people_company_office_foreign')
                ->references(['id', 'office_id'])->on('parties')->cascadeOnDelete();

            $table->foreign(['individual_party_id', 'office_id'], 'company_people_individual_office_foreign')
                ->references(['id', 'office_id'])->on('parties')->cascadeOnDelete();

            $table->string('relationship_type', 30);
            $table->string('position_name')->nullable();

            // Nullable: not every relationship carries a percentage, and an
            // absent value must not be confused with zero.
            $table->decimal('ownership_percentage', 7, 4)->nullable();

            $table->date('effective_from')->nullable();

            // NULL means current. The only authority on current-ness.
            $table->date('effective_until')->nullable();

            $table->foreignUlid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('company_party_id', 'company_people_company_index');
            $table->index('individual_party_id', 'company_people_individual_index');
            $table->index(['company_party_id', 'relationship_type'], 'company_people_company_type_index');
        });

        $types = implode("', '", CompanyRelationshipType::values());

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => Schema::getConnection()->statement(
                'ALTER TABLE company_people ADD CONSTRAINT company_people_relationship_type_check '
                ."CHECK (relationship_type IN ('{$types}'))"
            ),
            default => null,
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('company_people');
    }
};
