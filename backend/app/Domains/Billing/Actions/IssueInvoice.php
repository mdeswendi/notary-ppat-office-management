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
 * Send the bill (M8.2, D-124).
 *
 * **Issuing is the finalization act.** After it `invoices.update` refuses the
 * row and its lines, its figures are preserved, and the only remaining act is
 * `cancel` — `CLAUDE.md` section 64 applied to a commercial record rather than a
 * legal one, for the same reason: somebody outside the office has now seen it.
 *
 * **An invoice with no lines cannot be issued.** A bill for nothing is not a
 * bill, and the client would be asked to pay a total of zero that the office did
 * not mean. This is the only precondition here, and it is arithmetic rather than
 * an invented workflow rule.
 *
 * The catalogue's verb is `issue` and there is no `send`. Issuing *is* sending:
 * an issued invoice has gone to a client, and a second timestamp recording when
 * it was emailed would be a fact this software cannot actually observe.
 */
class IssueInvoice
{
    public function __construct(private readonly EventRecorder $events) {}

    public function handle(User $actor, Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($actor, $invoice): Invoice {
            if (! $invoice->status->isIssuable()) {
                throw BillingStatusNotEligible::for('invoice', $invoice->status->value, 'issued');
            }

            if ($invoice->items()->count() === 0) {
                throw BillingStatusNotEligible::for(
                    'invoice',
                    'empty',
                    'issued without at least one line',
                );
            }

            $from = $invoice->status->value;

            $invoice->status = InvoiceStatus::ISSUED;
            $invoice->issued_at = Date::now();
            $invoice->issued_by = $actor->getKey();
            $invoice->save();

            $this->events->statusChanged(
                $invoice,
                $actor,
                $from,
                $invoice->status->value,
                ActivityType::INVOICE_ISSUED,
                ['reference' => $invoice->invoice_number, 'title' => $invoice->title],
            );

            return $invoice;
        });
    }
}
