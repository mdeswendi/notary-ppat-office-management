<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Exceptions\BillingStatusNotEligible;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Withdraw a bill (M8.2, D-124).
 *
 * **This is what the catalogue offers instead of deletion.** There is no
 * `invoices.delete` code (O-051), so a draft raised in error is cancelled rather
 * than removed, and an issued invoice that should not have gone out is cancelled
 * rather than edited. Either way the row survives with its number and its
 * figures, which is what a financial record ought to do — corrections are
 * additive here (D-125).
 *
 * **A cancelled invoice asks for nothing.** {@see InvoiceStatus::isCollectable()}
 * excludes it from every outstanding figure, so cancelling settles the debt in
 * the only sense this software tracks.
 *
 * **Verified payments against it are not touched.** Money that arrived, arrived;
 * nothing here may rewrite a payment (O-050), and pretending it did not would be
 * worse than an invoice whose payments exceed what it now asks for. The reason
 * field is where somebody explains that situation.
 */
class CancelInvoice
{
    public function __construct(private readonly EventRecorder $events) {}

    public function handle(User $actor, Invoice $invoice, ?string $reason = null): Invoice
    {
        return DB::transaction(function () use ($actor, $invoice, $reason): Invoice {
            if (! $invoice->status->isCancellable()) {
                throw BillingStatusNotEligible::for('invoice', $invoice->status->value, 'cancelled');
            }

            $from = $invoice->status->value;

            $invoice->status = InvoiceStatus::CANCELLED;
            $invoice->cancelled_at = Date::now();
            $invoice->cancelled_by = $actor->getKey();
            $invoice->cancellation_reason = $reason;
            $invoice->save();

            // The reason reaches the audit row as well as the record, because
            // "why was this withdrawn" is the question somebody asks months later.
            $this->events->statusChanged(
                $invoice,
                $actor,
                $from,
                $invoice->status->value,
                ActivityType::INVOICE_CANCELLED,
                ['reference' => $invoice->invoice_number, 'title' => $invoice->title],
                $reason,
            );

            return $invoice;
        });
    }
}
