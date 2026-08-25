<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Documents satisfy which Warkah item (M7.1, D-121).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 19, with `office_id` added as the
 * composite carrier.
 *
 * **No surrogate `id`**, and that is the ERD's own shape: the canonical field list is
 * `warkah_item_id  document_id  attached_at  attached_by`. A composite primary key on
 * the pair, exactly as the three M5 document junctions have (D-116).
 *
 * **The pair is unique, unlike the M5 junctions.** D-116 and D-118 deliberately left
 * duplicates representable there, because no canonical document stated a cardinality
 * and *"a unique index is a business rule wearing an index's clothing"*. Here the
 * primary key **is** the pair, which the ERD chose by giving the table no `id` — so
 * the cardinality is transcribed rather than decided.
 *
 * **`attached_at` and `attached_by` record who and when**, which is what M5.3's
 * junctions do and what stands in for an audit store that does not exist (D-115).
 *
 * `RESTRICT` on the Document: a Warkah does not lose its evidence because somebody
 * tidied a document list. **CASCADE from the item**, because a link has no meaning
 * apart from the line it satisfies.
 *
 * **This is the table `completeness_percentage` counts.** An item is *collected* when
 * a row exists here for it — an observable fact needing no status vocabulary, which
 * is why `ppat_warkah_items.status` could be left empty (D-121).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppat_warkah_documents', function (Blueprint $table): void {
            $table->ulid('warkah_item_id');
            $table->ulid('document_id');

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            $table->timestamp('attached_at');
            $table->ulid('attached_by');

            // The ERD gives this table no `id`. The pair is the identity.
            $table->primary(['warkah_item_id', 'document_id']);

            $table->foreign('warkah_item_id', 'ppat_warkah_documents_item_foreign')
                ->references('id')->on('ppat_warkah_items')->cascadeOnDelete();

            $table->foreign('document_id', 'ppat_warkah_documents_document_foreign')
                ->references('id')->on('documents')->restrictOnDelete();

            $table->foreign(['warkah_item_id', 'office_id'], 'ppat_warkah_documents_item_office_foreign')
                ->references(['id', 'office_id'])->on('ppat_warkah_items')->cascadeOnDelete();

            $table->foreign(['document_id', 'office_id'], 'ppat_warkah_documents_document_office_foreign')
                ->references(['id', 'office_id'])->on('documents')->restrictOnDelete();

            $table->foreign(['attached_by', 'office_id'], 'ppat_warkah_documents_attached_by_office_foreign')
                ->references(['id', 'office_id'])->on('users')->restrictOnDelete();

            $table->index('document_id', 'ppat_warkah_documents_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppat_warkah_documents');
    }
};
