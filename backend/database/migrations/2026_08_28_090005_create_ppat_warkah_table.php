<?php

use App\Domains\Ppat\Enums\PpatWarkahStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warkah — the supporting documents bound with a PPAT Deed (M7.1, D-121).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 19, with `office_id` added as the
 * composite carrier and the `(id, office_id)` support key `ppat_warkah_items` needs.
 *
 * **Warkah has a canonical status vocabulary**, unlike `notary_minuta.release_status`
 * which had none. Five values, CHECK-constrained. That is a real difference between
 * the two domains and M7 uses it.
 *
 * ## `completeness_percentage` is stored, and what it counts is the ruling
 *
 * The column is canonical, so it is created. What M7 refuses is different and matters
 * more: **a percentage is meaningless without a denominator, and the denominator is
 * the mandatory Warkah composition per deed type that nobody has authored** — open
 * question three in `09_PPAT_WORKFLOW.md` section 6.
 *
 * So the number counts **the items the office itself created**, recomputed as items
 * change. No requirement template drives it — `ppat_warkah_items.requirement_code` is
 * stored and matched against nothing, because `service_document_requirements` and
 * `matter_requirements` are unbuilt (D-104).
 *
 * **100% does not mean legally complete.** It means every item this office listed has
 * a document attached. The interface must say so rather than implying sufficiency,
 * because a Warkah that is arithmetically full and legally short is exactly the
 * failure this refusal prevents.
 *
 * **No completeness figure gates any deed act.** Finalizing a PPAT deed with an empty
 * Warkah is permitted by the software, because *which* Warkah must be complete
 * *before what* is questions three and eight together.
 *
 * ## Two of the five statuses are unreachable
 *
 * `FINALIZED` and `ARCHIVED` are canonical values that no code path produces:
 * *"what are the binding/archiving requirements for deeds and supporting Warkah?"* is
 * open question eight, so `ppat.warkah.finalize` and `ppat.warkah.archive` stay
 * registered and unimplemented (D-064, O-041). The pair `finalized_at` /
 * `finalized_by` is written by nothing, kept honest by a CHECK for whichever
 * milestone eventually writes it.
 *
 * **One Warkah per Deed**, by unique index — the M6.3 ruling for Minuta applied to
 * the same shape of record. A Warkah is the supporting bundle *of one deed*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppat_warkah', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            $table->ulid('ppat_deed_id');

            $table->string('status', 30)->default(PpatWarkahStatus::INCOMPLETE->value);

            // Derived from the items this office created. See the class docblock.
            $table->unsignedTinyInteger('completeness_percentage')->default(0);

            $table->timestamp('verified_at')->nullable();
            $table->ulid('verified_by')->nullable();

            // Canonical columns nothing writes in M7.
            $table->timestamp('finalized_at')->nullable();
            $table->ulid('finalized_by')->nullable();

            // Free text: it describes a physical shelf, and inventing a structure
            // would be inventing the office's filing system (the M6.3 ruling for
            // `notary_minuta.archive_location`).
            $table->string('archive_location')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('ppat_deed_id', 'ppat_warkah_deed_foreign')
                ->references('id')->on('ppat_deeds')->restrictOnDelete();

            $table->foreign(['ppat_deed_id', 'office_id'], 'ppat_warkah_deed_office_foreign')
                ->references(['id', 'office_id'])->on('ppat_deeds')->restrictOnDelete();

            foreach ([
                'verified_by' => 'ppat_warkah_verified_by_office_foreign',
                'finalized_by' => 'ppat_warkah_finalized_by_office_foreign',
            ] as $column => $name) {
                $table->foreign([$column, 'office_id'], $name)
                    ->references(['id', 'office_id'])->on('users')->restrictOnDelete();
            }

            // One per deed — the term carries the cardinality.
            $table->unique('ppat_deed_id', 'ppat_warkah_deed_unique');

            // The support key `ppat_warkah_items` needs.
            $table->unique(['id', 'office_id'], 'ppat_warkah_id_office_id_unique');

            $table->index(['office_id', 'status'], 'ppat_warkah_office_status_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $statuses = implode("', '", PpatWarkahStatus::values());

            $connection->statement(
                "ALTER TABLE ppat_warkah ADD CONSTRAINT ppat_warkah_status_check CHECK (status IN ('{$statuses}'))"
            );

            // A percentage is a percentage. `unsignedTinyInteger` already bounds it
            // below on PostgreSQL via the smallint check Laravel emits; this bounds
            // it above.
            $connection->statement(
                'ALTER TABLE ppat_warkah ADD CONSTRAINT ppat_warkah_completeness_check '
                .'CHECK (completeness_percentage >= 0 AND completeness_percentage <= 100)'
            );

            foreach (['verified', 'finalized'] as $act) {
                $connection->statement(
                    "ALTER TABLE ppat_warkah ADD CONSTRAINT ppat_warkah_{$act}_pair_check "
                    ."CHECK (({$act}_at IS NULL AND {$act}_by IS NULL) "
                    ."OR ({$act}_at IS NOT NULL AND {$act}_by IS NOT NULL))"
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ppat_warkah');
    }
};
