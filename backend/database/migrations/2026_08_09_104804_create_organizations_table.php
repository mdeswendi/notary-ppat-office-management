<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization — the legal-office group this installation manages.
 *
 * Fields follow docs/03_DATABASE_ERD.md section 3. ULID primary key per D-023.
 *
 * V1 runs one active Organization per deployment (D-026), but that is an
 * application rule, not a schema one: no constraint pins the table to a single
 * row, so the decision stays reversible. Deliberately absent are `tenant_id`,
 * `slug`, `domain`, and any subscription, plan, or billing field — this is not
 * a SaaS tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('name');
            // Registered legal name, where it differs from the working name or
            // is not yet known. The ERD does not mark it required.
            $table->string('legal_name')->nullable();

            // Default office timezone per D-004. Timestamps are stored in UTC;
            // this drives display only, and nothing consumes it yet (M1.1 is
            // schema foundation — runtime configuration comes later).
            $table->string('timezone', 64)->default('Asia/Jakarta');
            // Indonesian is the primary UI language.
            $table->string('default_locale', 5)->default('id');

            // Retirement flag. There is no hard-delete capability for an
            // Organization — see docs/07_SECURITY_RULES.md section 22.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
