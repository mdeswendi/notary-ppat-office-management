<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of a Warkah (M7.1, D-121).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 19, with `office_id` added as the
 * composite carrier and the `(id, office_id)` support key `ppat_warkah_documents`
 * needs.
 *
 * ## `status` has no canonical vocabulary, so none is invented
 *
 * `ppat_warkah.status` **is** given five values by the ERD; `ppat_warkah_items.status`
 * is given **none**. The M7 brief proposed six — `MISSING`, `RECEIVED`,
 * `UNDER_REVIEW`, `VERIFIED`, `REJECTED`, `NOT_APPLICABLE` — and every one of them is
 * an invention.
 *
 * That matters more here than it would elsewhere, because an item-status vocabulary
 * *is* the Warkah verification rule: deciding an item can be `VERIFIED` decides that
 * somebody verifies items and what verification means, and *"what is the mandatory
 * Warkah composition per deed type?"* is open question three. So the column is created
 * as a nullable `VARCHAR` with no default and no CHECK — the
 * `notary_minuta.release_status` and `properties.status` treatment (D-120, D-121).
 *
 * **Completeness therefore counts documents, not statuses.** An item is *collected*
 * when a row exists in `ppat_warkah_documents` for it. That is observable, needs no
 * vocabulary, and is what `PpatWarkah::recalculateCompleteness()` uses.
 *
 * ## `requirement_code` is stored and matched against nothing
 *
 * The ERD names it, so it exists. What it would match — a requirement template — is
 * unbuilt: `service_document_requirements` and `matter_requirements` are D-104
 * territory and the M5 lock keeps them there. Treating this column as a foreign key
 * into a catalogue would be inventing the catalogue.
 *
 * ## `title_id` and `title_en` are database fields, not UI strings
 *
 * `CLAUDE.md` section 10 permits bilingual database columns for business data and
 * names exactly this case — the pattern `service_types` uses. They must **not** move
 * to `frontend/messages/`: a Warkah item title is content an office writes, not
 * interface chrome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppat_warkah_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            $table->ulid('warkah_id');

            // Stored, matched against nothing — see the class docblock.
            $table->string('requirement_code', 100)->nullable();

            // Bilingual business data (CLAUDE.md section 10), not UI strings.
            $table->string('title_id');
            $table->string('title_en');

            // Nullable: an item may belong to a specific party — a seller identity
            // document — or to the transaction as a whole, like a land certificate.
            $table->ulid('party_id')->nullable();

            // No vocabulary, no default, no CHECK. See the class docblock.
            $table->string('status', 30)->nullable();

            $table->unsignedInteger('sequence_no')->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('warkah_id', 'ppat_warkah_items_warkah_foreign')
                ->references('id')->on('ppat_warkah')->cascadeOnDelete();

            $table->foreign('party_id', 'ppat_warkah_items_party_foreign')
                ->references('id')->on('parties')->restrictOnDelete();

            // **CASCADE from the Warkah**, unlike almost everything else here: an
            // item has no meaning apart from the bundle it is a line of — the
            // reasoning `task_comments` and `notary_matters` used. The Warkah itself
            // is RESTRICT-protected from its deed, so this cannot fire while a deed
            // exists.
            $table->foreign(['warkah_id', 'office_id'], 'ppat_warkah_items_warkah_office_foreign')
                ->references(['id', 'office_id'])->on('ppat_warkah')->cascadeOnDelete();

            $table->foreign(['party_id', 'office_id'], 'ppat_warkah_items_party_office_foreign')
                ->references(['id', 'office_id'])->on('parties')->restrictOnDelete();

            // The support key `ppat_warkah_documents` needs.
            $table->unique(['id', 'office_id'], 'ppat_warkah_items_id_office_id_unique');

            $table->index(['warkah_id', 'sequence_no'], 'ppat_warkah_items_order_index');
            $table->index('party_id', 'ppat_warkah_items_party_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppat_warkah_items');
    }
};
