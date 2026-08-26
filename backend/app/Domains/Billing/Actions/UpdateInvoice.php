<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Audit\Services\EventRecorder;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\InvoicePolicy;
use Illuminate\Support\Facades\DB;

/**
 * Correct a draft offer's own fields (M8.2, D-124).
 *
 * **`DRAFT` only**, enforced by {@see InvoicePolicy::update()}
 * before this runs. Issuing is the finalization act, and an issued invoice
 * has been sent to a client.
 *
 * Only the fillable set moves: never the office, never the reference, never the
 * status, and never the totals — those are the sum of the lines and belong to
 * {@see ManageBillingLines}.
 *
 * **Audited, with no activity row.** A field correction is the D-128 case: it
 * goes to `audit_logs` with its old and new values, and stays off a timeline
 * nobody would want reporting every typo fix.
 */
class UpdateInvoice
{
    public function __construct(private readonly EventRecorder $events) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(User $actor, Invoice $invoice, array $attributes): Invoice
    {
        return DB::transaction(function () use ($actor, $invoice, $attributes): Invoice {
            $invoice->fill($attributes);
            $invoice->updated_by = $actor->getKey();
            $invoice->save();

            $this->events->updated($invoice, $actor);

            return $invoice;
        });
    }
}
