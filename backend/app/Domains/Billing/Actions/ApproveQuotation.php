<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Billing\Enums\QuotationStatus;
use App\Domains\Billing\Exceptions\BillingStatusNotEligible;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Record that a client agreed the price (M8.2, D-124).
 *
 * The only lifecycle act `quotations.*` authorizes. After it the quotation is
 * read-only: `CLAUDE.md` section 64's discipline for finalized records applies,
 * because the figures have been agreed with somebody outside the office.
 *
 * **Approving does not create an invoice.** Billing an agreed offer is
 * `POST /api/v1/invoices` carrying this quotation's id — a separate, deliberate
 * act under `invoices.create`. Doing it here would mean `quotations.approve`
 * silently conferred `invoices.create`, which is exactly the escalation D-091
 * forbids.
 */
class ApproveQuotation
{
    public function __construct(private readonly EventRecorder $events) {}

    public function handle(User $actor, Quotation $quotation): Quotation
    {
        return DB::transaction(function () use ($actor, $quotation): Quotation {
            if (! $quotation->status->isApprovable()) {
                throw BillingStatusNotEligible::for('quotation', $quotation->status->value, 'approved');
            }

            $from = $quotation->status->value;

            $quotation->status = QuotationStatus::APPROVED;
            $quotation->approved_at = Date::now();
            $quotation->approved_by = $actor->getKey();
            $quotation->save();

            // No amount in the metadata: `billing.amount.view` is a separate
            // gate and the feed consults no billing capability at all (D-125).
            $this->events->statusChanged(
                $quotation,
                $actor,
                $from,
                $quotation->status->value,
                ActivityType::QUOTATION_APPROVED,
                ['reference' => $quotation->quotation_number, 'title' => $quotation->title],
            );

            return $quotation;
        });
    }
}
