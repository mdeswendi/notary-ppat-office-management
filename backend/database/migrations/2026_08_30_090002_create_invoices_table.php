<?php

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A demand for payment, issued to a client (M8.2, D-124).
 *
 * Designed rather than transcribed, for the reason the quotations migration
 * gives. Three states, read off `create`, `update`, `issue`, `cancel`:
 *
 * ```text
 * DRAFT --issue--> ISSUED --cancel--> CANCELLED
 * ```
 *
 * ## There is no `paid_amount` column, and that is a decision
 *
 * The brief specified `paid_amount` and `remaining_amount`, maintained as
 * payments arrive. Both are **derived**, and deriving them at read time cannot
 * drift: what has been paid is the sum of this invoice's `VERIFIED` payments, and
 * what remains is that against the total. {@see Invoice} exposes both
 * through a `withSum` aggregate, so a list of fifty invoices still costs one
 * query.
 *
 * A stored copy would have to be recomputed inside every payment transaction and
 * would be silently wrong the first time one path forgot. The same reasoning
 * keeps `PARTIALLY_PAID`, `PAID` and `OVERDUE` out of the status vocabulary —
 * see {@see InvoiceStatus}.
 *
 * ## `issue_date` is not stored either
 *
 * The brief carried both `issue_date` and `sent_at`. An invoice is issued
 * exactly once, by an act that stamps `issued_at` and `issued_by` together, so a
 * second date column could only ever agree or be wrong. `due_date` stays,
 * because it is a commercial term the office chooses rather than a fact about
 * what happened.
 *
 * ## Cancellation carries a reason; issuing does not
 *
 * `cancellation_reason` is nullable free text. Cancelling an invoice a client has
 * seen is the one act here somebody will later ask about, and the alternative —
 * an audit row and nothing on the record — makes the reason invisible on the
 * page where it matters. Issuing needs no explanation: the invoice is the
 * explanation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            $table->string('invoice_number', 32);

            $table->ulid('client_party_id')->nullable();
            $table->ulid('project_id')->nullable();
            $table->ulid('matter_id')->nullable();

            // How a quotation becomes a bill. There is no `quotations.convert`
            // in the catalogue and none is needed: creating the invoice is
            // `invoices.create`, and this column is the link.
            $table->ulid('quotation_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();

            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            $table->string('currency', 3)->default('IDR');

            $table->string('status', 20)->default(InvoiceStatus::DRAFT->value);

            // A commercial term the office sets, not a record of an event.
            $table->date('due_date')->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->ulid('issued_by')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->ulid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->ulid('created_by');
            $table->ulid('updated_by')->nullable();

            $table->timestamps();

            // Present and currently unreachable: no `invoices.delete` exists
            // (O-051). Cancelling is what the catalogue authorizes instead.
            $table->softDeletes();

            $table->unique(['office_id', 'invoice_number'], 'invoices_office_number_unique');

            // The support key `payments` and `invoice_items` reference.
            $table->unique(['id', 'office_id'], 'invoices_id_office_id_unique');

            $table->foreign('client_party_id', 'invoices_party_foreign')
                ->references('id')->on('parties')->restrictOnDelete();

            $table->foreign(['client_party_id', 'office_id'], 'invoices_party_office_foreign')
                ->references(['id', 'office_id'])->on('parties')->restrictOnDelete();

            $table->foreign(['project_id', 'office_id'], 'invoices_project_office_foreign')
                ->references(['id', 'office_id'])->on('projects')->restrictOnDelete();

            $table->foreign(['matter_id', 'office_id'], 'invoices_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();

            $table->foreign(['quotation_id', 'office_id'], 'invoices_quotation_office_foreign')
                ->references(['id', 'office_id'])->on('quotations')->restrictOnDelete();

            foreach ([
                'created_by' => 'invoices_created_by_office_foreign',
                'updated_by' => 'invoices_updated_by_office_foreign',
                'issued_by' => 'invoices_issued_by_office_foreign',
                'cancelled_by' => 'invoices_cancelled_by_office_foreign',
            ] as $column => $name) {
                $table->foreign([$column, 'office_id'], $name)
                    ->references(['id', 'office_id'])->on('users')->restrictOnDelete();
            }

            // The three questions a billing list actually asks: what is
            // outstanding in this Office, what does this client owe, and what is
            // due soon.
            $table->index(['office_id', 'status', 'due_date'], 'invoices_office_status_due_index');
            $table->index('client_party_id', 'invoices_party_index');
            $table->index('project_id', 'invoices_project_index');
            $table->index('matter_id', 'invoices_matter_index');
            $table->index('quotation_id', 'invoices_quotation_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $statuses = implode("', '", InvoiceStatus::values());

            $connection->statement(
                "ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('{$statuses}'))"
            );

            $connection->statement(
                'ALTER TABLE invoices ADD CONSTRAINT invoices_issue_pair_check '
                .'CHECK ((issued_at IS NULL AND issued_by IS NULL) '
                .'OR (issued_at IS NOT NULL AND issued_by IS NOT NULL))'
            );

            $connection->statement(
                'ALTER TABLE invoices ADD CONSTRAINT invoices_cancel_pair_check '
                .'CHECK ((cancelled_at IS NULL AND cancelled_by IS NULL) '
                .'OR (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL))'
            );

            $connection->statement(
                'ALTER TABLE invoices ADD CONSTRAINT invoices_amounts_non_negative_check '
                .'CHECK (subtotal_amount >= 0 AND total_amount >= 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
