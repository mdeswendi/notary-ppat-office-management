<?php

use App\Domains\Billing\Enums\QuotationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A priced offer to a client (M8.2, D-124).
 *
 * **Designed, not transcribed.** `03_DATABASE_ERD.md` defines no billing table at
 * all — seventeen canonical capabilities and no schema anywhere (O-049). This is
 * the first schema any milestone in this project has designed rather than copied
 * from the ERD, and the lock's section 9.3 marks it as such so no future reader
 * mistakes it for canon.
 *
 * ## The status vocabulary is derived from the catalogue's verbs
 *
 * `quotations.view`, `.create`, `.update`, `.approve` — four codes, one of which
 * is a lifecycle verb. So:
 *
 * ```text
 * DRAFT --approve--> APPROVED
 * ```
 *
 * The M8.2 brief proposed six values: `DRAFT SENT ACCEPTED REJECTED EXPIRED
 * CONVERTED`. There is no `quotations.send`, `.reject`, `.expire` or `.convert`
 * in the catalogue — verified against the live registry — and the brief also
 * forbade adding permissions. **A quotation that comes to nothing stays `DRAFT`**,
 * which is the honest record of what the office knows.
 *
 * *Converting* a quotation is not lost: it is `POST /api/v1/invoices` carrying a
 * `quotation_id`, which answers to the canonical `invoices.create`. The link
 * lives on the invoice, where it belongs.
 *
 * ## There is no `tax` column, deliberately
 *
 * The brief specified `decimal tax`. D-124 section 9.4 forbids it in as many
 * words: no `tax_amount`, no rate, and no calculation deriving one figure from
 * another. Tax rules are named in `CLAUDE.md` section 62 among the things not to
 * invent, they are open question four in `09_PPAT_WORKFLOW.md`, and O-040 is
 * still open. An office that must show a tax enters it as a line item it names
 * and prices itself — a fact the office asserted, not a rule the software
 * encoded.
 *
 * ## `restrictOnDelete` everywhere, and `nullOnDelete` nowhere
 *
 * The brief used `->nullOnDelete()` on every composite key. **That fails at
 * runtime**: nulling a composite key nulls *both* its columns including
 * `office_id`, which is `NOT NULL`. `create_tasks_table` documents the trap and
 * M8.1 hit it. Refusing to delete a Party that still has quotations is also the
 * right answer — a priced offer does not become ownerless because somebody tried
 * to remove the client.
 *
 * ## Amounts are exact, never binary floats
 *
 * `decimal(15, 2)` is PostgreSQL `numeric`: exact, and what the lock's rule
 * actually requires. Rupiah has no minor unit in practice; the scale is there so
 * a future currency that does is representable without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            $table->string('quotation_number', 32);

            // **All three optional.** Office work is priced before it is filed:
            // a quotation may precede the Project, the Matter, or both, and
            // requiring a parent would make the ordinary case unrepresentable.
            $table->ulid('client_party_id')->nullable();
            $table->ulid('project_id')->nullable();
            $table->ulid('matter_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();

            // `subtotal_amount` is the sum of the lines; `total_amount` is what
            // the client is asked to pay. They are equal today and are kept
            // separate because a discount is a commercial fact rather than a tax
            // rule, and whoever adds one should not have to migrate.
            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            // ISO 4217. Not constrained to IDR: an office may quote in another
            // currency, and nothing here converts between them.
            $table->string('currency', 3)->default('IDR');

            $table->string('status', 20)->default(QuotationStatus::DRAFT->value);

            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();

            // Written together by `approve`, or not at all.
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();

            $table->ulid('created_by');
            $table->ulid('updated_by')->nullable();

            $table->timestamps();

            // **Present and currently unreachable.** The catalogue has no
            // `quotations.delete`, so nothing sets this — the D-109 pattern this
            // project records rather than repeats. The lock's section 9.3 lists
            // the column; a future catalogue extension would need it, and
            // O-051 records that the gap is known.
            $table->softDeletes();

            // Per Office and per year, never global. The brief's `->unique()`
            // would have made one Office's INV-2026-000001 block every other
            // Office's — the mistake D-103's namespacing exists to prevent.
            $table->unique(['office_id', 'quotation_number'], 'quotations_office_number_unique');

            // The support key an invoice's composite foreign key needs.
            $table->unique(['id', 'office_id'], 'quotations_id_office_id_unique');

            $table->foreign('client_party_id', 'quotations_party_foreign')
                ->references('id')->on('parties')->restrictOnDelete();

            $table->foreign(['client_party_id', 'office_id'], 'quotations_party_office_foreign')
                ->references(['id', 'office_id'])->on('parties')->restrictOnDelete();

            $table->foreign(['project_id', 'office_id'], 'quotations_project_office_foreign')
                ->references(['id', 'office_id'])->on('projects')->restrictOnDelete();

            $table->foreign(['matter_id', 'office_id'], 'quotations_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();

            foreach ([
                'created_by' => 'quotations_created_by_office_foreign',
                'updated_by' => 'quotations_updated_by_office_foreign',
                'approved_by' => 'quotations_approved_by_office_foreign',
            ] as $column => $name) {
                $table->foreign([$column, 'office_id'], $name)
                    ->references(['id', 'office_id'])->on('users')->restrictOnDelete();
            }

            $table->index(['office_id', 'status'], 'quotations_office_status_index');
            $table->index('client_party_id', 'quotations_party_index');
            $table->index('project_id', 'quotations_project_index');
            $table->index('matter_id', 'quotations_matter_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $statuses = implode("', '", QuotationStatus::values());

            // Raw SQL rather than `$table->check()`, which does not exist on
            // this Blueprint — verified, not assumed.
            $connection->statement(
                "ALTER TABLE quotations ADD CONSTRAINT quotations_status_check CHECK (status IN ('{$statuses}'))"
            );

            // An approved quotation carries both facts or neither. Half an
            // approval is a row nobody can explain.
            $connection->statement(
                'ALTER TABLE quotations ADD CONSTRAINT quotations_approval_pair_check '
                .'CHECK ((approved_at IS NULL AND approved_by IS NULL) '
                .'OR (approved_at IS NOT NULL AND approved_by IS NOT NULL))'
            );

            // Money is never negative here. A refund is not a negative
            // quotation, and nothing in this milestone issues one.
            $connection->statement(
                'ALTER TABLE quotations ADD CONSTRAINT quotations_amounts_non_negative_check '
                .'CHECK (subtotal_amount >= 0 AND total_amount >= 0)'
            );
        }

        // SQLite cannot add a CHECK after the fact, and the suite runs there.
        // The enum cast and the Action guards hold these on that connection.
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
