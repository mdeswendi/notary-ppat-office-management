<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a quotation offers and what an invoice charges for (M8.2, D-124).
 *
 * Two tables in one migration because they are the same shape for the same
 * reason, and separating them would invite the two from drifting apart.
 *
 * ## The line is where tax goes, if an office needs one
 *
 * D-124 section 9.4 forbids a `tax` column and any calculation that derives one
 * figure from another by a rate. An office that must show PPN on an invoice adds
 * a line it names and prices itself. **That is a fact the office asserted**, not
 * a rule this software encoded — and it keeps O-040 intact, which is still open
 * and which `CLAUDE.md` section 62 names explicitly among the things not to
 * invent.
 *
 * ## `line_amount` is stored, not computed on read
 *
 * Unlike an invoice's paid total, a line's amount is **not derivable from
 * anything that can change later**: it is `quantity * unit_amount` at the moment
 * the line was written. Recomputing it on read would silently rewrite history
 * the first time somebody corrected a unit price on a different line, and an
 * issued invoice must preserve its figures exactly (`CLAUDE.md` section 64).
 *
 * The Action writes it; nothing else may.
 *
 * ## `office_id` is carried, though the parent already has it
 *
 * It is the constraint carrier: without it there is no composite key to the
 * parent, and a line could point at another Office's invoice. Written from the
 * parent and never from the request — one source, so the two cannot disagree.
 * The document junctions (D-116) are built the same way for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            $table->ulid('quotation_id');

            // Caller-controlled ordering. An office lists work in the order it
            // will be done, which is not the order the rows were created.
            $table->unsignedSmallInteger('line_number')->default(1);

            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_amount', 15, 2)->default(0);
            $table->decimal('line_amount', 15, 2)->default(0);

            $table->timestamps();

            $table->foreign(['quotation_id', 'office_id'], 'quotation_items_quotation_office_foreign')
                ->references(['id', 'office_id'])->on('quotations')->cascadeOnDelete();

            $table->index(['quotation_id', 'line_number'], 'quotation_items_quotation_line_index');
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            $table->ulid('invoice_id');

            $table->unsignedSmallInteger('line_number')->default(1);

            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_amount', 15, 2)->default(0);
            $table->decimal('line_amount', 15, 2)->default(0);

            $table->timestamps();

            $table->foreign(['invoice_id', 'office_id'], 'invoice_items_invoice_office_foreign')
                ->references(['id', 'office_id'])->on('invoices')->cascadeOnDelete();

            $table->index(['invoice_id', 'line_number'], 'invoice_items_invoice_line_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            foreach (['quotation_items', 'invoice_items'] as $table) {
                $connection->statement(
                    "ALTER TABLE {$table} ADD CONSTRAINT {$table}_amounts_non_negative_check "
                    .'CHECK (quantity >= 0 AND unit_amount >= 0 AND line_amount >= 0)'
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('quotation_items');
    }
};
