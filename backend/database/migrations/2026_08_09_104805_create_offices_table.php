<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Office — an operating location belonging to exactly one Organization.
 *
 * Fields follow docs/03_DATABASE_ERD.md section 3. ULID primary key per D-023,
 * parentage per D-027.
 *
 * `code` carries NO uniqueness constraint. No canonical document defines one —
 * the word "unique" appears nowhere in the specification — and a composite
 * `organization_id + code` rule would be a long-term product rule invented in a
 * migration. Raised for decision rather than encoded silently.
 *
 * Deliberately absent: `tenant_id`, `parent_office_id` or any branch hierarchy,
 * an office-membership pivot, and legal-domain fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Required parent. `restrictOnDelete` so removing an Organization
            // cannot silently take its Offices with it — deletion must be a
            // deliberate, ordered act. Retirement uses `is_active`.
            //
            // Indexed explicitly: PostgreSQL does not index a referencing
            // column just because it carries a foreign key.
            $table->foreignUlid('organization_id')
                ->index()
                ->constrained('organizations')
                ->restrictOnDelete();

            $table->string('code');
            $table->string('name');

            // Contact and address details: nullable, since an office record is
            // useful before every field is known.
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();

            $table->string('timezone', 64)->default('Asia/Jakarta');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offices');
    }
};
