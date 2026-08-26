<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money the office spent on a client's behalf (M8.2, D-124).
 *
 * ## There is no `status` column, and none may be added
 *
 * `disbursements.*` carries `view`, `create` and `update` — **no lifecycle verb
 * at all**. A status column would therefore be vocabulary nothing could reach,
 * the D-109 pattern this project records as a cost rather than repeats as a
 * design. The M8.2 brief did not ask for one either; this note exists so nobody
 * adds one later on the assumption it was an oversight.
 *
 * ## A disbursement is a record, not a tax
 *
 * It says the office spent money for the client. **It does not know whether that
 * money was a tax, a fee or a courier charge, and it gates nothing.** That
 * distinction is what keeps O-040 intact: `ppat_tax_records` remains unbuilt,
 * which taxes gate which stage is an open domain question, and a disbursement is
 * not a back door to either. Nothing here computes a rate, and no figure is
 * derived from another.
 *
 * ## `invoice_id` is nullable, and re-billing is not automatic
 *
 * An office may attach a disbursement to the invoice that re-bills it. Nothing
 * copies the amount onto that invoice: adding a line is a deliberate act under
 * `invoices.update`, and a total that moved because a cost was recorded
 * elsewhere would change an invoice nobody edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // All optional: a cost may precede the engagement it belongs to.
            $table->ulid('project_id')->nullable();
            $table->ulid('matter_id')->nullable();
            $table->ulid('client_party_id')->nullable();
            $table->ulid('invoice_id')->nullable();

            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('IDR');

            // When the office spent it, which is not when somebody recorded it.
            $table->date('incurred_on');

            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->ulid('created_by');
            $table->ulid('updated_by')->nullable();

            $table->timestamps();

            // Present and currently unreachable: no `disbursements.delete`
            // exists (O-051).
            $table->softDeletes();

            $table->foreign('client_party_id', 'disbursements_party_foreign')
                ->references('id')->on('parties')->restrictOnDelete();

            $table->foreign(['client_party_id', 'office_id'], 'disbursements_party_office_foreign')
                ->references(['id', 'office_id'])->on('parties')->restrictOnDelete();

            $table->foreign(['project_id', 'office_id'], 'disbursements_project_office_foreign')
                ->references(['id', 'office_id'])->on('projects')->restrictOnDelete();

            $table->foreign(['matter_id', 'office_id'], 'disbursements_matter_office_foreign')
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();

            $table->foreign(['invoice_id', 'office_id'], 'disbursements_invoice_office_foreign')
                ->references(['id', 'office_id'])->on('invoices')->restrictOnDelete();

            foreach ([
                'created_by' => 'disbursements_created_by_office_foreign',
                'updated_by' => 'disbursements_updated_by_office_foreign',
            ] as $column => $name) {
                $table->foreign([$column, 'office_id'], $name)
                    ->references(['id', 'office_id'])->on('users')->restrictOnDelete();
            }

            $table->index(['office_id', 'incurred_on'], 'disbursements_office_incurred_index');
            $table->index('matter_id', 'disbursements_matter_index');
            $table->index('project_id', 'disbursements_project_index');
            $table->index('invoice_id', 'disbursements_invoice_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement(
                'ALTER TABLE disbursements ADD CONSTRAINT disbursements_amount_positive_check '
                .'CHECK (amount > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
    }
};
