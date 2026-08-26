<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic counters for quotation and invoice references (M8.2, D-124).
 *
 * The fourth counter table, after `project_reference_counters`,
 * `matter_reference_counters` and `document_reference_counters`. Same shape and
 * the same reason: `CLAUDE.md` section 38 and `03_DATABASE_ERD.md` section 27
 * both forbid `MAX + 1`, which is unsafe the moment two people create an invoice
 * in the same second.
 *
 * **One table for both sequences, with a `code` discriminator**, where the three
 * earlier allocators each got their own table. Two reasons: the ERD's own
 * `numbering_sequences` (section 27) is shaped exactly this way, with a `code`
 * column carrying `PROJECT`, `DOCUMENT` and the rest; and adding a fifth and
 * sixth single-purpose table for two sequences that will always be allocated by
 * the same domain is duplication rather than separation.
 *
 * `matter_reference_counters` already set the precedent for a discriminator — it
 * carries `domain` so Notary and PPAT count separately.
 *
 * **Namespaced by Office and calendar year** (D-103): each Office counts from 1
 * each January, and an Office never sees another's sequence. The primary key is
 * the namespace, so the upsert has exactly one row to conflict on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_reference_counters', function (Blueprint $table): void {
            $table->foreignUlid('office_id')
                ->constrained('offices')
                ->restrictOnDelete();

            // `QUOTATION` or `INVOICE`. A short varchar rather than an enum,
            // per CLAUDE.md section 13 — and unconstrained by a CHECK, because
            // this is an internal counter key rather than a business vocabulary.
            $table->string('code', 20);

            $table->unsignedSmallInteger('reference_year');

            $table->unsignedInteger('last_value')->default(0);

            $table->timestamps();

            // The namespace *is* the key: one row per Office, per sequence, per
            // year, and the upsert's conflict target.
            $table->primary(['office_id', 'code', 'reference_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reference_counters');
    }
};
