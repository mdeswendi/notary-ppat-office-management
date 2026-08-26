<?php

use App\Domains\Billing\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money received against an invoice (M8.2, D-124, D-125, O-050).
 *
 * Two states, read off two verbs — `payments.create`, `payments.verify`:
 *
 * ```text
 * PENDING --verify--> VERIFIED
 * ```
 *
 * ## This table has no `deleted_at` and no `updated_by`, and that is the point
 *
 * The catalogue gives payments **no `update`, no `delete` and no `reject`** —
 * verified against the live registry. Adding either column would imply an act
 * nothing authorizes, so neither is here. This is the one billing surface with
 * no correction path at all, and M8.2 ships that honestly rather than inventing
 * an uncatalogued verb (O-050) — the same disposition M7.3 gave one-way property
 * archiving (O-045).
 *
 * **The verify gate is the only control there is.** Only `VERIFIED` payments
 * count toward an invoice's paid total, so a mis-entered payment caught before
 * verification affects no figure anywhere. It stays visible and uncounted rather
 * than hidden, because somebody needs to see that it was entered. What has no
 * remedy is a payment verified in error.
 *
 * ## `created_at` is not `paid_at`
 *
 * `paid_at` is the date the office says the money moved, which is routinely
 * earlier than the moment somebody typed it in — a transfer noticed on Monday
 * may have landed on Friday. Reconciliation reads `paid_at`; the audit trail
 * reads `created_at`. Conflating them would make a late entry look like a late
 * payment.
 *
 * ## No integration reads any of this
 *
 * M8.2 records payments; it does not process them. `method_code` and `reference`
 * exist to be filtered and displayed, nothing branches on either, and no
 * capability in the catalogue describes taking money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // **Required, unlike every other billing parent.** A payment with no
            // invoice is money the office cannot account for; the record it
            // belongs on is the one it settles.
            $table->ulid('invoice_id');

            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('IDR');

            $table->string('status', 20)->default(PaymentStatus::PENDING->value);

            $table->string('method_code', 30);
            $table->string('reference')->nullable();

            // When the money moved, per the office. See the class docblock for
            // why this is not `created_at`.
            $table->date('paid_at');

            $table->text('notes')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->ulid('verified_by')->nullable();

            $table->ulid('created_by');

            // No `softDeletes()`, and no `updated_by`. See the class docblock.
            $table->timestamps();

            $table->foreign(['invoice_id', 'office_id'], 'payments_invoice_office_foreign')
                ->references(['id', 'office_id'])->on('invoices')->restrictOnDelete();

            foreach ([
                'created_by' => 'payments_created_by_office_foreign',
                'verified_by' => 'payments_verified_by_office_foreign',
            ] as $column => $name) {
                $table->foreign([$column, 'office_id'], $name)
                    ->references(['id', 'office_id'])->on('users')->restrictOnDelete();
            }

            $table->index(['office_id', 'status'], 'payments_office_status_index');
            $table->index('invoice_id', 'payments_invoice_index');
            $table->index('paid_at', 'payments_paid_at_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $statuses = implode("', '", PaymentStatus::values());

            $connection->statement(
                "ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('{$statuses}'))"
            );

            $connection->statement(
                'ALTER TABLE payments ADD CONSTRAINT payments_verify_pair_check '
                .'CHECK ((verified_at IS NULL AND verified_by IS NULL) '
                .'OR (verified_at IS NOT NULL AND verified_by IS NOT NULL))'
            );

            // A payment of nothing is not a payment. Negative would be a refund,
            // which no capability in the catalogue describes.
            $connection->statement(
                'ALTER TABLE payments ADD CONSTRAINT payments_amount_positive_check CHECK (amount > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
