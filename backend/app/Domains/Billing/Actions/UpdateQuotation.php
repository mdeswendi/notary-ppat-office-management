<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Audit\Services\EventRecorder;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update an office price record and its details (M8.2, D-124).
 *
 * Only the fillable set moves: never the office, never the reference, never the
 * legacy status, and never the totals — those are the sum of the lines and belong to
 * {@see ManageBillingLines}.
 *
 * **Audited, with no activity row.** A field correction is the D-128 case: it
 * goes to `audit_logs` with its old and new values, and stays off a timeline
 * nobody would want reporting every typo fix.
 */
class UpdateQuotation
{
    public function __construct(
        private readonly EventRecorder $events,
        private readonly ManageBillingLines $lines,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(User $actor, Quotation $quotation, array $attributes, ?array $items = null): Quotation
    {
        return DB::transaction(function () use ($actor, $quotation, $attributes, $items): Quotation {
            $quotation->fill($attributes);
            $quotation->updated_by = $actor->getKey();
            $quotation->save();

            if ($items !== null) {
                $this->lines->replaceQuotation($actor, $quotation, $items);
            } else {
                $this->events->updated($quotation, $actor);
            }

            return $quotation;
        });
    }
}
