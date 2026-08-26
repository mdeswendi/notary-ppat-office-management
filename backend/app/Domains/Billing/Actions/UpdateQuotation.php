<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Audit\Services\EventRecorder;
use App\Models\Quotation;
use App\Models\User;
use App\Policies\QuotationPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Correct a draft offer's own fields (M8.2, D-124).
 *
 * **`DRAFT` only**, enforced by {@see QuotationPolicy::update()}
 * before this runs. Approving is the finalization act, and an approved quotation
 * carries figures a client has agreed to.
 *
 * Only the fillable set moves: never the office, never the reference, never the
 * status, and never the totals — those are the sum of the lines and belong to
 * {@see ManageBillingLines}.
 *
 * **Audited, with no activity row.** A field correction is the D-128 case: it
 * goes to `audit_logs` with its old and new values, and stays off a timeline
 * nobody would want reporting every typo fix.
 */
class UpdateQuotation
{
    public function __construct(private readonly EventRecorder $events) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(User $actor, Quotation $quotation, array $attributes): Quotation
    {
        return DB::transaction(function () use ($actor, $quotation, $attributes): Quotation {
            $quotation->fill($attributes);
            $quotation->updated_by = $actor->getKey();
            $quotation->save();

            $this->events->updated($quotation, $actor);

            return $quotation;
        });
    }
}
